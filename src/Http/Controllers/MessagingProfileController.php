<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\ChannelMeta;
use Iquesters\SmartMessenger\Models\ChannelProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Iquesters\SmartMessenger\Constants\Constants;

class MessagingProfileController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                abort(401);
            }

            $organisationIds = collect();

            // User may or may not support organisations
            if (method_exists($user, 'organisations')) {
                $organisationIds = $user
                    ->organisations()
                    ->pluck('organisations.id');
            }

            $channels = Channel::query()
                ->where(function ($query) use ($user, $organisationIds) {

                    // Personal channels
                    $query->where('created_by', $user->id);

                    // Organisation channels (only if Channel supports it)
                    if (
                        $organisationIds->isNotEmpty() &&
                        method_exists(Channel::class, 'organisations')
                    ) {
                        $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                            $q->whereIn('organisations.id', $organisationIds);
                        });
                    }
                })
                ->with([
                    'provider',
                    'metas',
                    method_exists(Channel::class, 'organisations') ? 'organisations' : null,
                ])
                ->get();

            $channelProviders = ChannelProvider::where('status', Constants::ACTIVE)
                ->with('metas')
                ->get();

            return view(
                'smartmessenger::channels.index',
                compact('channels', 'channelProviders')
            );

        } catch (\Throwable $e) {

            Log::error('Channel index error', [
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return redirect()
                ->back()
                ->with(Constants::ERROR, $e->getMessage());
        }
    }

    /**
     * Create channel (step-based)
     */
    public function create()
    {
        try {
            $step       = request()->query('step', 1);
            $providerId = request()->query('provider_id');
            $providers = ChannelProvider::where('status', Constants::ACTIVE)->get();
            $selectedProvider = null;

            if ($providerId) {
                $selectedProvider = $providers->where('uid', $providerId)->first();

                if (!$selectedProvider) {
                    return redirect()->route('channels.index')
                        ->with(Constants::ERROR, 'Invalid provider selected.');
                }
            }

            $sessionData = session('channel_step1_data', []);

            if ($step == 2 && empty($sessionData)) {
                return redirect()->route('channels.create')
                    ->with(Constants::ERROR, 'Please complete Step 1 first.');
            }

            if ($step == 2 && !empty($sessionData)) {
                $selectedProvider = $providers
                    ->where('id', $sessionData['channel_provider_id'])
                    ->first();
            }

            $organisations = auth()->user()->organisations ?? collect();

            return view('smartmessenger::channels.form', [
                'isEdit'        => false,
                'channel'       => null,
                'providers'     => $providers,
                'provider'      => $selectedProvider,
                'organisations' => $organisations,
                'step'          => $step,
                'sessionData'   => $sessionData,
            ]);

        } catch (\Throwable $e) {
            Log::error('Channel create error', [Constants::ERROR => $e->getMessage()]);
            return redirect()->route('channels.index')->with(Constants::ERROR, $e->getMessage());
        }
    }

    /**
     * Store Step 1
     */
    public function storeStep1(Request $request)
    {
        try {
            $user = auth()->user();

            $userOrgIds = method_exists($user, 'organisations')
                ? $user->organisations()->pluck('id')->toArray()
                : [];

            $rules = [
                'channel_provider_id' => 'required|integer|exists:channel_providers,id',
                'name'                => 'required|string|max:255',
                'organisation_id'     => ['nullable','integer'],
            ];

            if (!empty($userOrgIds)) {
                $rules['organisation_id'][] = 'in:' . implode(',', $userOrgIds);
            }

            $data = $request->validate($rules);

            session(['channel_step1_data' => $data]);

            return redirect()->route('channels.create', ['step' => 2]);

        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }
    }

    /**
     * Store Channel (Step 2)
     */
    public function store(Request $request)
    {
        try {
            $user      = auth()->user();
            $step1Data = session('channel_step1_data');

            if (empty($step1Data)) {
                return redirect()->route('channels.create')
                    ->with(Constants::ERROR, 'Session expired. Please start again.');
            }

            /**
             * 🔑 IMPORTANT
             * Generic validation because providers may share the same meta keys
             */
            $step2Data = $request->validate([
                'meta' => 'required|array',
            ]);

            $channel = new Channel();
            $channel->uid                = (string) Str::ulid();
            $channel->name               = $step1Data['name'];
            $channel->user_id            = $user->id;
            $channel->channel_provider_id= $step1Data['channel_provider_id'];
            $channel->status             = 'active';
            $channel->created_by         = $user->id;
            $channel->save();

            $channel->setMeta(
                'webhook_verify_token',
                Str::random(40)
            );

            if (!empty($step1Data['organisation_id'])) {
                try {
                    $org = Organisation::findOrFail($step1Data['organisation_id']);
                    $channel->assignOrganisation($org->uid);
                } catch (\Throwable $e) {
                    Log::warning('Channel org assign failed', [
                        'channel_id' => $channel->id
                    ]);
                }
            }

            foreach ($step2Data['meta'] as $key => $value) {
                ChannelMeta::create([
                    'ref_parent' => $channel->id,
                    'meta_key'   => $key,
                    'meta_value' => $value,
                    'created_by' => $user->id,
                ]);
            }

            session()->forget('channel_step1_data');

            return redirect()->route('channels.index')
                ->with(Constants::SUCCESS, 'Channel created successfully.');

        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }
    }

    /**
     * Edit channel
     */
    public function edit($uid)
    {
        try {
            $channel = Channel::where('uid', $uid)->firstOrFail();
            $step    = request()->query('step', 1);

            $providers = ChannelProvider::all();
            $provider  = $providers->where('id', $channel->channel_provider_id)->first();

            $sessionData = session('channel_step1_data', [
                'channel_provider_id' => $channel->channel_provider_id,
                'name' => $channel->name,
                'organisation_id' => optional($channel->organisations->first())->id,
            ]);

            $organisations = auth()->user()->organisations ?? collect();

            return view('smartmessenger::channels.form', [
                'isEdit'        => true,
                'channel'       => $channel,
                'providers'     => $providers,
                'provider'      => $provider,
                'organisations' => $organisations,
                'step'          => $step,
                'sessionData'   => $sessionData,
            ]);

        } catch (\Throwable $e) {
            Log::error('Channel edit error', [Constants::ERROR => $e->getMessage()]);
            return redirect()->route('channels.index')->with(Constants::ERROR, 'Unable to load channel.');
        }
    }

    /**
     * Update Step 1
     */
    public function updateStep1(Request $request, $uid)
    {
        try {
            $channel = Channel::where('uid', $uid)->firstOrFail();

            $data = $request->validate([
                'channel_provider_id' => 'required|integer|exists:channel_providers,id',
                'name' => 'required|string|max:255',
                'organisation_id' => 'nullable|integer',
            ]);

            session(['channel_step1_data' => $data]);

            return redirect()->route('channels.edit', ['profileUid' => $uid, 'step' => 2]);

        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    /**
     * Update Channel (Step 2)
     */
    public function update(Request $request, $uid)
    {
        try {
            $channel = Channel::where('uid', $uid)->firstOrFail();
            $step1Data = session('channel_step1_data');

            if (empty($step1Data)) {
                return redirect()->route('channels.edit', $uid)
                    ->with(Constants::ERROR, 'Session expired.');
            }

            // Step 2 validation
            $step2Data = $request->validate([
                'meta' => 'required|array',
            ]);

            // Update basic channel info
            $channel->name = $step1Data['name'];
            $channel->channel_provider_id = $step1Data['channel_provider_id'];
            $channel->updated_by = auth()->id();
            $channel->save();

            // Update organisation association (convert ID -> UID)
            if (!empty($step1Data['organisation_id'])) {
                $org = Organisation::find($step1Data['organisation_id']);
                if ($org) {
                    $channel->syncOrganisations([$org->uid]);
                } else {
                    $channel->syncOrganisations([]);
                    Log::warning('Invalid organisation during channel update', [
                        'channel_uid' => $uid,
                        'organisation_id' => $step1Data['organisation_id'],
                    ]);
                }
            } else {
                $channel->syncOrganisations([]);
            }

            // Update meta values
            foreach ($step2Data['meta'] as $key => $value) {
                $channel->setMeta($key, $value);
            }

            // Clear session
            session()->forget('channel_step1_data');

            return redirect()->route('channels.index')
                ->with(Constants::SUCCESS, 'Channel updated successfully.');

        } catch (\Throwable $e) {
            Log::error('Channel update error', [
                'channel_uid' => $uid,
                Constants::ERROR => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with(Constants::ERROR, $e->getMessage());
        }
    }
    
    public function show($channelUid)
    {
        try {
            $userId = auth()->id();

            $channel = Channel::where([
                    'uid'        => $channelUid,
                    'created_by' => $userId,
                ])
                ->with(['metas', 'provider'])
                ->firstOrFail();
                
            // Provider (WhatsApp currently)
            $provider = $channel->provider;

            // WhatsApp specific values
            $webhook_url = url('/webhook/whatsapp/' . $channel->uid);
            $webhook_verify_token = $channel->getMeta('webhook_verify_token');
            
            Log::debug('Channel Show', [
                'provider'    => $provider,
                'webhook_verify_token'=> $webhook_verify_token,
            ]);
            return view(
                'smartmessenger::channels.show',
                compact(
                    'channel',
                    'provider',
                    'webhook_url',
                    'webhook_verify_token'
                )
            );

        } catch (\Throwable $e) {

            Log::error('Channel Show Error', [
                'user_id'    => auth()->id(),
                'channel_uid'=> $channelUid,
                Constants::ERROR      => $e->getMessage(),
            ]);

            return redirect()
                ->route('channels.index')
                ->with(Constants::ERROR, 'Unable to load channel.');
        }
    }

    
    public function destroy($uid)
    {
        try {
            $userId = auth()->id();

            $profile = Channel::where('uid', $uid)->firstOrFail();

            // Soft delete by updating status
            $profile->status = 'deleted';
            $profile->updated_by = $userId;
            $profile->save();

            Log::info('Channel Soft Deleted', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            return redirect()->route('channels.index')
                ->with(Constants::SUCCESS, 'Channel deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('Channel Delete Error', [
                'user_id' => $userId,
                Constants::ERROR => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with(Constants::ERROR, 'Error deleting Channel: ' . $e->getMessage());
        }
    }
}