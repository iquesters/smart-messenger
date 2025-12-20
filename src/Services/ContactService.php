<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\MessagingProfile;
use Iquesters\Foundation\Models\MasterData;

class ContactService
{
    /**
     * Create a new contact
     *
     * @param array $data ['name', 'identifier', 'messaging_profile_id', 'status'?]
     * @param int $userId
     * @return Contact
     * @throws \Exception
     */
    public function createContact(array $data, int $userId): Contact
    {
        // Validate data
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'identifier' => 'required|string|max:50',
            'messaging_profile_id' => 'required|exists:messaging_profiles,id',
            'status' => 'nullable|string|in:active,inactive,blocked',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Verify the messaging profile belongs to the user
        $profile = MessagingProfile::where('id', $data['messaging_profile_id'])
            ->where('created_by', $userId)
            ->with('metas')
            ->first();

        if (!$profile) {
            throw new \Exception('Messaging profile not found or access denied');
        }

        // Check for duplicate identifier
        $existingContact = Contact::where('identifier', $data['identifier'])->first();

        if ($existingContact) {
            throw new \Exception('A contact with this identifier already exists');
        }

        // Create contact
        $contact = Contact::create([
            'uid' => (string) Str::ulid(),
            'name' => $data['name'],
            'identifier' => $data['identifier'],
            'status' => $data['status'] ?? 'active',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        // Get provider identifier from profile
        $providerIdentifier = $profile->metas
            ->where('meta_key', 'whatsapp_phone_number_id')
            ->pluck('meta_value')
            ->first();

        // Build and save profile_details meta
        $this->updateContactProfileDetails($contact, $profile, $providerIdentifier);

        Log::info('Contact created via service', [
            'user_id' => $userId,
            'contact_id' => $contact->id,
            'contact_uid' => $contact->uid
        ]);

        return $contact;
    }

    /**
     * Create or update contact from webhook
     * Simpler validation for webhook context
     *
     * @param string $identifier
     * @param string|null $name
     * @param MessagingProfile $profile
     * @return Contact
     */
    public function createOrUpdateFromWebhook(
        string $identifier,
        ?string $name,
        MessagingProfile $profile
    ): Contact {
        
        $contact = Contact::where('identifier', $identifier)->first();

        if ($contact) {
            // Update existing contact if name provided
            if ($name && $name !== $contact->name) {
                $contact->name = $name;
                $contact->updated_by = $profile->created_by;
                $contact->save();

                Log::info('Contact updated from webhook', [
                    'contact_id' => $contact->id,
                    'identifier' => $identifier,
                    'new_name' => $name
                ]);
            }
        } else {
            // Create new contact
            $contact = Contact::create([
                'uid' => (string) Str::ulid(),
                'name' => $name ?? $identifier,
                'identifier' => $identifier,
                'status' => 'active'
            ]);

            Log::info('Contact created from webhook', [
                'contact_id' => $contact->id,
                'identifier' => $identifier,
                'name' => $contact->name
            ]);
        }

        // Update profile details
        $providerIdentifier = $profile->metas
            ->where('meta_key', 'whatsapp_phone_number_id')
            ->pluck('meta_value')
            ->first();

        $this->updateContactProfileDetails($contact, $profile, $providerIdentifier);

        return $contact;
    }

    /**
     * Update contact (name only)
     *
     * @param string $uid
     * @param array $data ['name']
     * @param int $userId
     * @return Contact
     * @throws \Exception
     */
    public function updateContact(string $uid, array $data, int $userId): Contact
    {
        // Validate data
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Get user's messaging profiles
        $profiles = MessagingProfile::where('created_by', $userId)
            ->with('metas')
            ->get();

        if ($profiles->isEmpty()) {
            throw new \Exception('No messaging profiles found');
        }

        // Extract provider identifiers
        $providerIdentifiers = $profiles->map(function ($profile) {
            return $profile->metas
                ->where('meta_key', 'whatsapp_phone_number_id')
                ->pluck('meta_value')
                ->first();
        })->filter()->unique()->values();

        if ($providerIdentifiers->isEmpty()) {
            throw new \Exception('No provider identifiers found');
        }

        // Find contact that belongs to the user
        $contact = Contact::with('metas')
            ->where('uid', $uid)
            ->whereHas('metas', function ($query) use ($providerIdentifiers) {
                $query->where('meta_key', 'profile_details')
                    ->where(function ($q) use ($providerIdentifiers) {
                        foreach ($providerIdentifiers as $identifier) {
                            $q->orWhere('meta_value', 'LIKE', '%"provider_identifier":"' . $identifier . '"%');
                        }
                    });
            })
            ->first();

        if (!$contact) {
            throw new \Exception('Contact not found or access denied');
        }

        // Update contact
        $contact->name = $data['name'];
        $contact->updated_by = $userId;
        $contact->save();

        Log::info('Contact updated via service', [
            'user_id' => $userId,
            'contact_uid' => $uid,
            'new_name' => $data['name']
        ]);

        return $contact;
    }

    /**
     * Get all contacts for user's messaging profiles
     *
     * @param int $userId
     * @return \Illuminate\Support\Collection
     */
    public function getUserContacts(int $userId)
    {
        // Load providers master data
        $providers = MasterData::with('metas')
            ->get()
            ->mapWithKeys(function ($provider) {
                return [
                    $provider->id => [
                        'id' => $provider->id,
                        'name' => $provider->key,
                        'meta' => $provider->metas
                            ->pluck('meta_value', 'meta_key')
                            ->toArray(),
                    ]
                ];
            });

        // Load messaging profiles
        $profiles = MessagingProfile::where('created_by', $userId)
            ->with('metas')
            ->get();

        if ($profiles->isEmpty()) {
            return collect([]);
        }

        // Build lookup
        $profileNameByIdentifier = [];
        foreach ($profiles as $profile) {
            $identifier = $profile->metas
                ->where('meta_key', 'whatsapp_phone_number_id')
                ->pluck('meta_value')
                ->first();

            if ($identifier) {
                $profileNameByIdentifier[$identifier] = $profile->name;
            }
        }

        // Collect provider identifiers
        $providerIdentifiers = collect(array_keys($profileNameByIdentifier));

        // Fetch contacts
        $contacts = Contact::with('metas')
            ->whereHas('metas', function ($query) use ($providerIdentifiers) {
                $query->where('meta_key', 'profile_details')
                    ->where(function ($q) use ($providerIdentifiers) {
                        foreach ($providerIdentifiers as $identifier) {
                            $q->orWhere(
                                'meta_value',
                                'LIKE',
                                '%"provider_identifier":"' . $identifier . '"%'
                            );
                        }
                    });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($contact) use ($providers, $profileNameByIdentifier) {
                $meta = $contact->metas->mapWithKeys(function ($m) use ($providers, $profileNameByIdentifier) {
                    $value = $m->meta_value;

                    if (is_string($value) && json_decode($value, true) !== null) {
                        $value = json_decode($value, true);
                    }

                    // Enhance profile_details
                    if ($m->meta_key === 'profile_details') {
                        if (isset($value['provider']) && isset($providers[$value['provider']])) {
                            $provider = $providers[$value['provider']];
                            $value['provider'] = [
                                'id' => $provider['id'],
                                'name' => $provider['name'],
                                'icon' => $provider['meta']['icon'] ?? null,
                            ];
                        }

                        if (isset($value['provider_identifier'])) {
                            $value['profile_name'] =
                                $profileNameByIdentifier[$value['provider_identifier']] ?? null;
                        }
                    }

                    return [$m->meta_key => $value];
                })->toArray();

                return [
                    'id' => $contact->id,
                    'uid' => $contact->uid,
                    'name' => $contact->name,
                    'identifier' => $contact->identifier,
                    'status' => $contact->status,
                    'meta' => $meta,
                    'created_at' => $contact->created_at?->toIso8601String(),
                    'updated_at' => $contact->updated_at?->toIso8601String(),
                ];
            });

        return $contacts;
    }

    /**
     * Update contact profile details meta
     *
     * @param Contact $contact
     * @param MessagingProfile $profile
     * @param string|null $providerIdentifier
     * @return void
     */
    private function updateContactProfileDetails(
        Contact $contact,
        MessagingProfile $profile,
        ?string $providerIdentifier
    ): void {
        $profileDetails = [
            'uid' => $contact->uid,
            'identifier' => $contact->identifier,
            'provider' => $profile->provider_id,
            'provider_identifier' => $providerIdentifier,
            'default' => true,
            'preferred' => true,
            'status' => 'active',
        ];

        $contact->setMetaValue(
            'profile_details',
            json_encode($profileDetails)
        );

        Log::debug('Contact profile details updated', [
            'contact_id' => $contact->id,
            'profile_id' => $profile->id,
        ]);
    }
}