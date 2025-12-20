<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\Foundation\Models\MasterData;
use Iquesters\SmartMessenger\Models\MessagingProfile;

class ContactController extends Controller
{
    /**
     * Get all contacts linked to authenticated user's messaging profiles
     */
    public function index(): JsonResponse
{
    try {
        $userId = auth()->id();

        /**
         * STEP 0: Load providers master data (icons, etc.)
         */
        $providers = MasterData::with('metas')->get()
            ->mapWithKeys(function ($provider) {
                return [
                    $provider->id => [
                        'id'   => $provider->id,
                        'name' => $provider->key, // you said you have `key`, not `name`
                        'meta' => $provider->metas
                            ->pluck('meta_value', 'meta_key')
                            ->toArray(),
                    ]
                ];
            });

        /**
         * STEP 1: Load messaging profiles
         */
        $profiles = MessagingProfile::where('created_by', $userId)
            ->with('metas')
            ->get();

        if ($profiles->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        /**
         * STEP 2: Build lookup
         * whatsapp_phone_number_id => profile_name
         */
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

        /**
         * STEP 3: Collect provider identifiers
         */
        $providerIdentifiers = collect(array_keys($profileNameByIdentifier));

        /**
         * STEP 4: Fetch contacts
         */
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

                    /**
                     * Enhance profile_details
                     */
                    if ($m->meta_key === 'profile_details') {

                        // Replace provider ID with provider object
                        if (isset($value['provider']) && isset($providers[$value['provider']])) {
                            $provider = $providers[$value['provider']];

                            $value['provider'] = [
                                'id'   => $provider['id'],
                                'name' => $provider['name'],
                                'icon' => $provider['meta']['icon'] ?? null,
                            ];
                        }

                        // Attach profile name
                        if (isset($value['provider_identifier'])) {
                            $value['profile_name'] =
                                $profileNameByIdentifier[$value['provider_identifier']] ?? null;
                        }
                    }

                    return [$m->meta_key => $value];
                })->toArray();

                return [
                    'id'         => $contact->id,
                    'uid'        => $contact->uid,
                    'name'       => $contact->name,
                    'identifier' => $contact->identifier,
                    'status'     => $contact->status,
                    'meta'       => $meta,
                    'created_at' => $contact->created_at?->toIso8601String(),
                    'updated_at' => $contact->updated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $contacts,
            'meta' => [
                'total' => $contacts->count(),
            ],
        ]);

    } catch (\Throwable $e) {
        Log::error('Failed to fetch contacts', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to load contacts',
            'error' => app()->environment('local') ? $e->getMessage() : null,
        ], 500);
    }
}



    /**
     * Get a single contact by UID
     */
    // public function show(string $uid): JsonResponse
    // {
    //     try {
    //         $userId = auth()->id();

    //         Log::info('Fetching contact details.', [
    //             'user_id' => $userId,
    //             'contact_uid' => $uid
    //         ]);

    //         // Get user's messaging profiles
    //         $profiles = MessagingProfile::where('created_by', $userId)
    //             ->with('metas')
    //             ->get();

    //         if ($profiles->isEmpty()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No messaging profiles found'
    //             ], 404);
    //         }

    //         // Extract provider identifiers
    //         $providerIdentifiers = $profiles->map(function ($profile) {
    //             return $profile->metas
    //                 ->where('meta_key', 'whatsapp_phone_number_id')
    //                 ->pluck('meta_value')
    //                 ->first();
    //         })->filter()->unique()->values();

    //         if ($providerIdentifiers->isEmpty()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No provider identifiers found'
    //             ], 404);
    //         }

    //         // Find contact
    //         $contact = Contact::with('metas')
    //             ->where('uid', $uid)
    //             ->whereHas('metas', function ($query) use ($providerIdentifiers) {
    //                 $query->where('meta_key', 'profile_details')
    //                     ->where(function ($q) use ($providerIdentifiers) {
    //                         foreach ($providerIdentifiers as $identifier) {
    //                             $q->orWhere('meta_value', 'LIKE', '%"provider_identifier":"' . $identifier . '"%');
    //                         }
    //                     });
    //             })
    //             ->first();

    //         if (!$contact) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Contact not found'
    //             ], 404);
    //         }

    //         $contactData = [
    //             'id'         => $contact->id,
    //             'uid'        => $contact->uid,
    //             'name'       => $contact->name,
    //             'identifier' => $contact->identifier,
    //             'status'     => $contact->status,
    //             'profile'    => $this->extractProfileDetails($contact),
    //             'created_at' => $contact->created_at?->toIso8601String(),
    //             'updated_at' => $contact->updated_at?->toIso8601String(),
    //         ];

    //         Log::info('Contact fetched successfully.', [
    //             'user_id' => $userId,
    //             'contact_uid' => $uid
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'data'    => $contactData
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error('Failed to fetch contact', [
    //             'user_id' => auth()->id(),
    //             'contact_uid' => $uid,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to load contact',
    //             'error' => app()->environment('local') ? $e->getMessage() : null
    //         ], 500);
    //     }
    // }
}