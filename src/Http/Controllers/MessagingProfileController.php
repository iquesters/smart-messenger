<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Routing\Controller;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\SmartMessenger\Models\MessagingProfile;
use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Models\MasterData;
use Illuminate\Support\Str;

class MessagingProfileController extends Controller
{
    public function index()
    {
        try {
            $userId = auth()->id();

            Log::info('Fetching messaging profiles for user.', [
                'user_id' => $userId
            ]);

            $profiles = MessagingProfile::where('created_by', $userId)
                ->with('metas')
                ->get();

            Log::info('Messaging profiles fetched successfully.', [
                'user_id' => $userId,
                'profiles_count' => $profiles->count()
            ]);
            
            // 1. Get "provider" parent entry
            $providerParent = MasterData::where('key', 'provider')->where('parent_id', 0)->first();

            // 2. Get all provider children under this parent
            $providers = [];
            if ($providerParent) {
                $providers = MasterData::where('parent_id', $providerParent->id)->get();
            }
            
            return view('smartmessenger::messaging-profiles.index', compact('profiles', 'providers'));
        } catch (\Exception $e) {
            Log::error('Failed to fetch messaging profiles', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to load messaging profiles');
        }
    }

    public function create()
    {
        try {
            $step = request()->query('step', 1);
            $providerId = request()->query('provider_id');

            // Provider parent
            $providerParent = MasterData::where('key', 'provider')
                ->where('parent_id', 0)
                ->first();

            if (!$providerParent) {
                return redirect()
                    ->route('profiles.index')
                    ->with('error', 'Provider configuration is missing.');
            }

            // All providers (for dropdown)
            $providers = MasterData::where('parent_id', $providerParent->id)->get();

            $selectedProvider = null;

            // If provider_id is passed, validate it
            if ($providerId) {
                $selectedProvider = $providers->where('id', $providerId)->first();

                if (!$selectedProvider) {
                    return redirect()
                        ->route('profiles.index')
                        ->with('error', 'Invalid provider selected.');
                }
            }

            // Get data from session for Step 2
            $sessionData = session('profile_step1_data', []);

            // If on Step 2, validate that Step 1 data exists
            if ($step == 2 && empty($sessionData)) {
                return redirect()
                    ->route('profiles.create')
                    ->with('error', 'Please complete Step 1 first.');
            }

            // If Step 2 and we have session data, use it
            if ($step == 2 && !empty($sessionData)) {
                $selectedProvider = $providers->where('id', $sessionData['provider_id'] ?? null)->first();
            }

            $organisations = auth()->user()->organisations ?? collect();

            return view('smartmessenger::messaging-profiles.form', [
                'isEdit'        => false,
                'profile'       => null,
                'providers'     => $providers,
                'provider'      => $selectedProvider,
                'providerId'    => $selectedProvider?->id,
                'organisations' => $organisations,
                'step'          => $step,
                'assignedOrganisationId' => null,
                'sessionData'   => $sessionData,
            ]);

        } catch (\Throwable $e) {
            Log::error('Messaging Profile Create Error', [
                'message' => $e->getMessage()
            ]);

            return redirect()
                ->route('profiles.index')
                ->with('error', 'Something went wrong.');
        }
    }

    public function storeStep1()
    {
        try {
            $user = auth()->user();
            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            // Validate Step 1 data
            $data = request()->validate([
                'provider_id' => [
                    'required',
                    'integer',
                    'exists:master_data,id'
                ],
                'name' => 'required|string|max:255',
                'organisation_id' => ['nullable', 'integer', 'in:' . implode(',', $userOrgIds)],
            ]);

            // Store Step 1 data in session
            session(['profile_step1_data' => $data]);

            Log::info('Profile Step 1 data stored in session', [
                'user_id' => $user->id,
                'provider_id' => $data['provider_id']
            ]);

            // Redirect to Step 2
            return redirect()->route('profiles.create', ['step' => 2]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            Log::error('Profile Step 1 Error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return back()->withInput()->with('error', 'Error processing form: ' . $e->getMessage());
        }
    }

    public function store()
    {
        try {
            $user = auth()->user();
            $userId = $user->id;

            // Get Step 1 data from session
            $step1Data = session('profile_step1_data');

            if (empty($step1Data)) {
                return redirect()
                    ->route('profiles.create')
                    ->with('error', 'Session expired. Please start again.');
            }

            // Get IDs of organisations the user belongs to
            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            // Validate Step 2 data
            $step2Data = request()->validate([
                'meta.whatsapp_business_id'     => 'required|string|max:50',
                'meta.whatsapp_phone_number_id' => 'required|string|max:50|unique:messaging_profile_metas,meta_value',
                'meta.system_user_token'        => 'required|string|max:2000',
                'meta.country_code'             => 'required|string|max:10',
                'meta.whatsapp_number'          => 'required|string|max:20',
            ]);

            Log::info('Messaging Profile Store: Validation passed.', [
                'user_id' => $userId,
                'provider_id' => $step1Data['provider_id']
            ]);

            // Create profile
            $profile = new MessagingProfile();
            $profile->uid         = (string) Str::ulid();
            $profile->provider_id = $step1Data['provider_id'];
            $profile->name        = $step1Data['name'];
            $profile->status      = 'active';
            $profile->created_by  = $userId;
            $profile->save();

            // Generate webhook verification token (per profile)
            $profile->setMetaValue(
                'webhook_verify_token',
                Str::random(40)
            );

            Log::info('Messaging Profile Store: Profile created', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            // Assign organisation if valid
            if (!empty($step1Data['organisation_id'])) {
                try {
                    // Resolve UID from ID
                    $organisation = Organisation::findOrFail($step1Data['organisation_id']);
                    $profile->assignOrganisation($organisation->uid);

                    Log::info('Messaging Profile Store: Organisation assigned', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $organisation->id,
                        'organisation_uid' => $organisation->uid
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Messaging Profile Store: Organisation assignment failed', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $step1Data['organisation_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Save meta fields
            if (!empty($step2Data['meta'])) {
                foreach ($step2Data['meta'] as $key => $value) {
                    if (!empty($value)) {
                        $profile->setMetaValue($key, $value);
                    }
                }
                Log::info('Messaging Profile Store: Meta saved', [
                    'profile_id' => $profile->id,
                    'meta_keys' => array_keys($step2Data['meta'])
                ]);
            }

            // Clear session data
            session()->forget('profile_step1_data');

            return redirect()->route('profiles.index')->with([
                'success' => 'Messaging Profile created successfully.'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());

        } catch (\Throwable $e) {
            Log::error('Messaging Profile Store Error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withInput()
                ->with('error', 'Error creating Messaging Profile: ' . $e->getMessage());
        }
    }
    
    public function show($profileUid)
    {
        try {
            $userId = auth()->id();

            $profile = MessagingProfile::where([
                'uid' => $profileUid,
                'created_by' => $userId,
            ])->with('metas')->firstOrFail();

            $provider = MasterData::find($profile->provider_id);
            
            $webhook_url = url('/webhook/whatsapp');
            $webhook_verify_token = $profile->getMeta('webhook_verify_token');
            
            return view('smartmessenger::messaging-profiles.show', compact('profile', 'provider', 'webhook_url', 'webhook_verify_token'));
        } catch (\Throwable $e) {
            Log::error('Messaging Profile Show Error', [
                'user_id' => auth()->id(),
                'profile_uid' => $profileUid,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('profiles.index')
                ->with('error', 'Unable to load profile.');
        }
    }

    public function edit($uid)
    {
        try {
            $user = auth()->user();
            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            $step = request()->query('step', 1);

            // Load provider from MasterData
            $provider = MasterData::find($profile->provider_id);

            // Get all providers for dropdown
            $providerParent = MasterData::where('key', 'provider')->where('parent_id', 0)->first();
            $providers = $providerParent 
                ? MasterData::where('parent_id', $providerParent->id)->get() 
                : collect();

            // Get user organisations
            $organisations = $user->organisations ?? collect();
            
            $assignedOrganisationId = $profile->organisations()->pluck('id')->first();

            // Get data from session for Step 2
            $sessionData = session("profile_edit_step1_data_{$uid}", []);

            // If on Step 2 and no session data, use profile data
            if ($step == 2 && empty($sessionData)) {
                $sessionData = [
                    'name' => $profile->name,
                    'organisation_id' => $assignedOrganisationId,
                    'status' => $profile->status,
                ];
            }

            return view('smartmessenger::messaging-profiles.form', [
                'isEdit'        => true,
                'profile'       => $profile,
                'providers'     => $providers,
                'provider'      => $provider,
                'providerId'    => $profile->provider_id,
                'organisations' => $organisations,
                'assignedOrganisationId' => $assignedOrganisationId,
                'step'          => $step,
                'sessionData'   => $sessionData,
            ]);
        } catch (\Throwable $e) {
            Log::error('Messaging Profile Edit Error', [
                'user_id' => auth()->id(),
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('profiles.index')
                ->with('error', 'Unable to load profile for editing.');
        }
    }

    public function updateStep1($uid)
    {
        try {
            $user = auth()->user();
            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            // Validate Step 1 data
            $data = request()->validate([
                'name' => 'required|string|max:255',
                'organisation_id' => ['nullable', 'integer', 'in:' . implode(',', $userOrgIds)],
                'status' => 'required|in:active,inactive',
            ]);

            // Store Step 1 data in session with profile UID
            session(["profile_edit_step1_data_{$uid}" => $data]);

            Log::info('Profile Edit Step 1 data stored in session', [
                'user_id' => $user->id,
                'profile_uid' => $uid
            ]);

            // Redirect to Step 2
            return redirect()->route('profiles.edit', ['profileUid' => $uid, 'step' => 2]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            Log::error('Profile Edit Step 1 Error', [
                'user_id' => auth()->id(),
                'profile_uid' => $uid,
                'error' => $e->getMessage()
            ]);

            return back()->withInput()->with('error', 'Error processing form: ' . $e->getMessage());
        }
    }
    
    public function update($uid)
    {
        try {
            $user = auth()->user();
            $userId = $user->id;

            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            // Get Step 1 data from session
            $step1Data = session("profile_edit_step1_data_{$uid}");

            if (empty($step1Data)) {
                return redirect()
                    ->route('profiles.edit', $uid)
                    ->with('error', 'Session expired. Please start again.');
            }

            // Get IDs of organisations the user belongs to
            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            // Validate Step 2 data
            $step2Data = request()->validate([
                'meta.whatsapp_business_id'     => 'required|string|max:50',
                'meta.whatsapp_phone_number_id' => 'required|string|max:50',
                'meta.system_user_token'        => 'required|string|max:2000',
                'meta.country_code'             => 'required|string|max:10',
                'meta.whatsapp_number'          => 'required|string|max:20',
            ]);

            Log::info('Messaging Profile Update: Validation passed.', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            // Update profile fields from Step 1 data
            $profile->name   = $step1Data['name'];
            $profile->status = $step1Data['status'];
            $profile->updated_by = $userId;
            $profile->save();

            // Sync organisation (if provided)
            if (!empty($step1Data['organisation_id'])) {
                try {
                    $profile->syncOrganisations([$step1Data['organisation_id']]);
                    Log::info('Messaging Profile Update: Organisation assigned', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $step1Data['organisation_id']
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Messaging Profile Update: Organisation assignment failed', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $step1Data['organisation_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update meta
            if (!empty($step2Data['meta'])) {
                foreach ($step2Data['meta'] as $key => $value) {
                    if (!empty($value)) {
                        $profile->setMetaValue($key, $value);
                    }
                }
                Log::info('Messaging Profile Update: Meta updated', [
                    'profile_id' => $profile->id,
                    'meta_keys' => array_keys($step2Data['meta'])
                ]);
            }

            // Clear session data
            session()->forget("profile_edit_step1_data_{$uid}");

            return redirect()->route('profiles.index')
                ->with('success', 'Messaging Profile updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());

        } catch (\Throwable $e) {
            Log::error('Messaging Profile Update Error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withInput()->with('error', 'Error updating Messaging Profile: ' . $e->getMessage());
        }
    }
    
    public function destroy($uid)
    {
        try {
            $userId = auth()->id();

            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            // Soft delete by updating status
            $profile->status = 'deleted';
            $profile->updated_by = $userId;
            $profile->save();

            Log::info('Messaging Profile Soft Deleted', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            return redirect()->route('profiles.index')
                ->with('success', 'Messaging Profile deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('Messaging Profile Delete Error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with('error', 'Error deleting Messaging Profile: ' . $e->getMessage());
        }
    }
}