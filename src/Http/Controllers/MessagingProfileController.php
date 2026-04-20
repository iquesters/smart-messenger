<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\Foundation\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\ChannelMeta;
use Iquesters\SmartMessenger\Models\ChannelProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Iquesters\SmartMessenger\Constants\Constants;
use Laravel\Socialite\Facades\Socialite;

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

            $channelProviders = ChannelProvider::all();

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

            $provider = ChannelProvider::findOrFail($data['channel_provider_id']);

            if ($this->isGmailProvider($provider)) {
                $channel = $this->createChannelFromStep1Data($data, $user);
                session()->forget('channel_step1_data');

                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::SUCCESS, 'Gmail channel saved. Connect Google to complete setup.');
            }

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

            $provider = ChannelProvider::find($step1Data['channel_provider_id']);

            if ($provider && $this->isGmailProvider($provider)) {
                $channel = $this->createChannelFromStep1Data($step1Data, $user);
                session()->forget('channel_step1_data');

                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::SUCCESS, 'Gmail channel saved. Connect Google to complete setup.');
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

            $provider = ChannelProvider::findOrFail($data['channel_provider_id']);

            if ($this->isGmailProvider($provider)) {
                $channel->name = $data['name'];
                $channel->channel_provider_id = $data['channel_provider_id'];
                $channel->updated_by = auth()->id();
                $channel->save();

                $this->syncChannelOrganisation($channel, $data['organisation_id'] ?? null);
                session()->forget('channel_step1_data');

                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::SUCCESS, 'Gmail channel saved.');
            }

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
            $this->syncChannelOrganisation($channel, $step1Data['organisation_id'] ?? null);

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
            $channel = $this->findAccessibleChannel($channelUid);
                
            $provider = $channel->provider;

            $webhook_url = $this->isWhatsAppProvider($provider)
                ? url('/webhook/whatsapp/' . $channel->uid)
                : null;
            $webhook_verify_token = $this->isWhatsAppProvider($provider)
                ? $channel->getMeta('webhook_verify_token')
                : null;
            
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

    public function connectGmail($profileUid)
    {
        try {
            $channel = $this->findAccessibleChannel($profileUid);

            if (!$this->isGmailProvider($channel->provider)) {
                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::ERROR, 'Google connection is only available for Gmail channels.');
            }

            $googleProvider = $this->getGoogleProviderConfig();

            if (!$googleProvider) {
                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::ERROR, 'Google OAuth is not configured.');
            }

            Session::put('gmail_channel_uid', $channel->uid);

            return $this->buildGoogleProvider($googleProvider)
                ->scopes($this->gmailScopes())
                ->with([
                    'access_type' => 'offline',
                    'prompt' => 'consent select_account',
                    'include_granted_scopes' => 'true',
                ])
                ->redirect();

        } catch (\Throwable $e) {
            Log::error('Gmail connect redirect error', [
                'channel_uid' => $profileUid,
                Constants::ERROR => $e->getMessage(),
            ]);

            return redirect()
                ->route('channels.index')
                ->with(Constants::ERROR, 'Unable to start Google connection.');
        }
    }

    public function gmailCallback(Request $request)
    {
        $channelUid = Session::pull('gmail_channel_uid');

        if (!$channelUid) {
            return redirect()
                ->route('channels.index')
                ->with(Constants::ERROR, 'Google connection session expired. Please try again.');
        }

        try {
            $channel = $this->findAccessibleChannel($channelUid);

            if (!$this->isGmailProvider($channel->provider)) {
                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::ERROR, 'Invalid Gmail channel selected.');
            }

            $googleProvider = $this->getGoogleProviderConfig();

            if (!$googleProvider) {
                return redirect()
                    ->route('channels.show', $channel->uid)
                    ->with(Constants::ERROR, 'Google OAuth is not configured.');
            }

            $googleUser = $this->buildGoogleProvider($googleProvider)->user();

            $channel->setMeta('gmail_connected_email', $googleUser->getEmail());
            $channel->setMeta('gmail_google_id', $googleUser->getId());
            $channel->setMeta('gmail_display_name', $googleUser->getName());
            $channel->setMeta('gmail_token_expires_at', now()->addSeconds((int) ($googleUser->expiresIn ?? 3600))->toDateTimeString());
            $channel->setMeta('gmail_scopes', implode(' ', $this->gmailScopes()));
            $channel->setMeta('gmail_connected_at', now()->toDateTimeString());
            $channel->setMeta('gmail_connection_status', 'connected');

            if (!empty($googleUser->token)) {
                $channel->setMeta('gmail_access_token', Crypt::encryptString($googleUser->token));
            }

            if (!empty($googleUser->refreshToken)) {
                $channel->setMeta('gmail_refresh_token', Crypt::encryptString($googleUser->refreshToken));
            }

            return redirect()
                ->route('channels.show', $channel->uid)
                ->with(Constants::SUCCESS, 'Google connected successfully.');

        } catch (\Throwable $e) {
            Log::error('Gmail OAuth callback error', [
                'channel_uid' => $channelUid,
                Constants::ERROR => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('channels.show', $channelUid)
                ->with(Constants::ERROR, 'Google connection failed. Please try again.');
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

    protected function createChannelFromStep1Data(array $step1Data, $user): Channel
    {
        $channel = new Channel();
        $channel->uid = (string) Str::ulid();
        $channel->name = $step1Data['name'];
        $channel->user_id = $user->id;
        $channel->channel_provider_id = $step1Data['channel_provider_id'];
        $channel->status = 'active';
        $channel->created_by = $user->id;
        $channel->save();

        $this->syncChannelOrganisation($channel, $step1Data['organisation_id'] ?? null);

        return $channel;
    }

    protected function syncChannelOrganisation(Channel $channel, $organisationId): void
    {
        if (!method_exists($channel, 'syncOrganisations')) {
            return;
        }

        if (empty($organisationId)) {
            $channel->syncOrganisations([]);
            return;
        }

        $org = Organisation::find($organisationId);

        if ($org) {
            $channel->syncOrganisations([$org->uid]);
            return;
        }

        $channel->syncOrganisations([]);
        Log::warning('Invalid organisation during channel sync', [
            'channel_uid' => $channel->uid,
            'organisation_id' => $organisationId,
        ]);
    }

    protected function findAccessibleChannel(string $channelUid): Channel
    {
        $userId = auth()->id();
        $relations = ['metas', 'provider'];

        if (method_exists(Channel::class, 'organisations')) {
            $relations[] = 'organisations';
        }

        return Channel::where('uid', $channelUid)
            ->where(function ($query) use ($userId) {
                $query->where('created_by', $userId);

                if (method_exists(Channel::class, 'organisations') && auth()->user() && method_exists(auth()->user(), 'organisations')) {
                    $orgIds = auth()->user()->organisations()->pluck('organisations.id');

                    if ($orgIds->isNotEmpty()) {
                        $query->orWhereHas('organisations', function ($q) use ($orgIds) {
                            $q->whereIn('organisations.id', $orgIds);
                        });
                    }
                }
            })
            ->with($relations)
            ->firstOrFail();
    }

    protected function isGmailProvider(?ChannelProvider $provider): bool
    {
        return strtolower((string) ($provider->small_name ?? '')) === 'gmail';
    }

    protected function isWhatsAppProvider(?ChannelProvider $provider): bool
    {
        return strtolower((string) ($provider->small_name ?? '')) === 'whatsapp';
    }

    protected function getGoogleProviderConfig(): ?array
    {
        $clientId = env('SMART_MESSENGER_GMAIL_GOOGLE_CLIENT_ID')
            ?: env('USER_MANAGEMENT_GOOGLE_CLIENT_ID')
            ?: config('services.google.client_id');

        $clientSecret = env('SMART_MESSENGER_GMAIL_GOOGLE_CLIENT_SECRET')
            ?: env('USER_MANAGEMENT_GOOGLE_CLIENT_SECRET')
            ?: config('services.google.client_secret');

        $redirectUrl = env('SMART_MESSENGER_GMAIL_GOOGLE_REDIRECT_URI')
            ?: route('channels.gmail.callback');

        try {
            $userManagementConfig = ConfProvider::from(Module::USER_MGMT);
            $googleConfig = $userManagementConfig->social_login->o_auth_providers['google'] ?? null;

            $clientId = $clientId ?: ($googleConfig->client_id ?? null);
            $clientSecret = $clientSecret ?: ($googleConfig->client_secret ?? null);
        } catch (\Throwable $e) {
            Log::debug('User management Google config unavailable for Gmail channel OAuth', [
                Constants::ERROR => $e->getMessage(),
            ]);
        }

        if (empty($clientId) || empty($clientSecret) || empty($redirectUrl)) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $redirectUrl,
        ];
    }

    protected function buildGoogleProvider(array $googleProvider)
    {
        return Socialite::buildProvider(
            \Laravel\Socialite\Two\GoogleProvider::class,
            $googleProvider
        );
    }

    protected function gmailScopes(): array
    {
        $configuredScopes = env('SMART_MESSENGER_GMAIL_GOOGLE_SCOPES');

        if ($configuredScopes) {
            return array_values(array_filter(preg_split('/[\s,]+/', $configuredScopes)));
        }

        return [
            'openid',
            'profile',
            'email',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/gmail.readonly',
        ];
    }
}
