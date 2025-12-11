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
            $providerId = request()->query('provider_id');

            // 1. Get "provider" parent record (same as index)
            $providerParent = MasterData::where('key', 'provider')
                ->where('parent_id', 0)
                ->first();

            if (!$providerParent) {
                return redirect()
                    ->route('messaging-profiles.index')
                    ->with('error', 'Provider configuration is missing.');
            }

            // 2. Check that selected provider belongs to this parent
            $provider = MasterData::where('parent_id', $providerParent->id)
                ->where('id', $providerId)
                ->first();

            if (!$provider) {
                return redirect()
                    ->route('messaging-profiles.index')
                    ->with('error', 'Invalid or unknown provider selected.');
            }

            // 3. User organisations (may be empty)
            $organisations = auth()->user()->organisations ?? collect();

            return view('smartmessenger::messaging-profiles.form', [
                'isEdit'        => false,
                'profile'       => null,
                'providerId'    => $provider->id,
                'provider'      => $provider,
                'organisations' => $organisations
            ]);
        } catch (\Throwable $e) {

            Log::error('Messaging Profile Create Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()
                ->route('messaging-profiles.index')
                ->with('error', 'Something went wrong while opening the profile creation page.');
        }
    }

    public function store()
    {
        try {
            $user = auth()->user();
            $userId = $user->id;

            // Get IDs of organisations the user belongs to
            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            // Validate input
            $data = request()->validate([
                'provider_id'     => 'required|integer|exists:master_data,id',
                'name'            => 'required|string|max:255',
                'organisation_id' => ['nullable', 'integer','in:'.implode(',', $userOrgIds)],
                'meta.whatsapp_business_id'     => 'nullable|string|max:50',
                'meta.whatsapp_phone_number_id' => 'nullable|string|max:50',
                'meta.system_user_token'        => 'nullable|string|max:255',
            ]);

            Log::info('Messaging Profile Store: Validation passed.', [
                'user_id' => $userId,
                'provider_id' => $data['provider_id']
            ]);

            // Create profile
            $profile = new MessagingProfile();
            $profile->uid         = (string) Str::ulid();
            $profile->provider_id = $data['provider_id'];
            $profile->name        = $data['name'];
            $profile->status      = 'active';
            $profile->created_by  = $userId;
            $profile->save();

            Log::info('Messaging Profile Store: Profile created', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            // Assign organisation if valid
            if (!empty($data['organisation_id'])) {
                try {
                    // Resolve UID from ID
                    $organisation = Organisation::findOrFail($data['organisation_id']);
                    $profile->assignOrganisation($organisation->uid);

                    Log::info('Messaging Profile Store: Organisation assigned', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $organisation->id,
                        'organisation_uid' => $organisation->uid
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Messaging Profile Store: Organisation assignment failed', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $data['organisation_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Save meta fields
            if (!empty($data['meta'])) {
                foreach ($data['meta'] as $key => $value) {
                    if (!empty($value)) {
                        $profile->setMetaValue($key, $value);
                    }
                }
                Log::info('Messaging Profile Store: Meta saved', [
                    'profile_id' => $profile->id,
                    'meta_keys' => array_keys($data['meta'])
                ]);
            }

            return redirect()->route('profiles.index')
                ->with('success', 'Messaging Profile created successfully.');

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

    public function edit($uid)
    {
        try {
            $user = auth()->user();
            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            // Load provider from MasterData
            $provider = MasterData::find($profile->provider_id);

            // Get user organisations
            $organisations = $user->organisations ?? collect();
            
            $assignedOrganisationId = $profile->organisations()->pluck('id')->first();

            return view('smartmessenger::messaging-profiles.form', [
                'isEdit'        => true,
                'profile'       => $profile,
                'provider'      => $provider,
                'providerId'    => $profile->provider_id,
                'organisations' => $organisations,
                'assignedOrganisationId' => $assignedOrganisationId,
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
    
    public function update($uid)
    {
        try {
            $user = auth()->user();
            $userId = $user->id;

            $profile = MessagingProfile::where('uid', $uid)->firstOrFail();

            // Get IDs of organisations the user belongs to
            $userOrgIds = $user->organisations()->pluck('id')->toArray();

            $data = request()->validate([
                'name'            => 'required|string|max:255',
                'organisation_id' => ['nullable', 'integer', 'in:' . implode(',', $userOrgIds)],
                'status'          => 'required|in:active,inactive',
                'meta.whatsapp_business_id'     => 'nullable|string|max:50',
                'meta.whatsapp_phone_number_id' => 'nullable|string|max:50',
                'meta.system_user_token'        => 'nullable|string|max:255',
            ]);

            Log::info('Messaging Profile Update: Validation passed.', [
                'user_id' => $userId,
                'profile_id' => $profile->id
            ]);

            // Update profile fields
            $profile->name   = $data['name'];
            $profile->status = $data['status'];
            $profile->updated_by = $userId;
            $profile->save();

            // Sync organisation (if provided)
            if (!empty($data['organisation_id'])) {
                try {
                    $profile->syncOrganisations([$data['organisation_id']]);
                    Log::info('Messaging Profile Update: Organisation assigned', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $data['organisation_id']
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Messaging Profile Update: Organisation assignment failed', [
                        'profile_id' => $profile->id,
                        'organisation_id' => $data['organisation_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update meta
            if (!empty($data['meta'])) {
                foreach ($data['meta'] as $key => $value) {
                    if (!empty($value)) {
                        $profile->setMetaValue($key, $value);
                    }
                }
                Log::info('Messaging Profile Update: Meta updated', [
                    'profile_id' => $profile->id,
                    'meta_keys' => array_keys($data['meta'])
                ]);
            }

            return redirect()->route('profiles.index')
                ->with('success', 'Messaging Profile updated successfully.');

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