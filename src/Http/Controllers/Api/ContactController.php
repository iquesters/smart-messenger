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

            Log::info('Fetching contacts for user via messaging profiles.', [
                'user_id' => $userId
            ]);

            /**
             * Step 0: Load all provider master data with metas (icon, etc.)
             * Adjust the column if your master data uses a different way to filter providers
             */
            $providers = MasterData::with('metas')->get()
                ->mapWithKeys(function ($provider) {
                    return [
                        $provider->id => [
                            'id'   => $provider->id,
                            'name' => $provider->key,
                            'meta' => $provider->metas->pluck('meta_value', 'meta_key')->toArray(),
                        ]
                    ];
                });

            /**
             * Step 1: Get user's messaging profiles
             */
            $profiles = MessagingProfile::where('created_by', $userId)
                ->with('metas')
                ->get();

            if ($profiles->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No messaging profiles found'
                ]);
            }

            /**
             * Step 2: Extract whatsapp_phone_number_id(s)
             */
            $providerIdentifiers = $profiles->map(function ($profile) {
                return $profile->metas
                    ->where('meta_key', 'whatsapp_phone_number_id')
                    ->pluck('meta_value')
                    ->first();
            })->filter()->unique()->values();

            if ($providerIdentifiers->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No provider identifiers found'
                ]);
            }

            /**
             * Step 3: Get contacts matching provider_identifier in profile_details
             */
            $contacts = Contact::with('metas')
                ->whereHas('metas', function ($query) use ($providerIdentifiers) {
                    $query->where('meta_key', 'profile_details')
                        ->where(function ($q) use ($providerIdentifiers) {
                            foreach ($providerIdentifiers as $identifier) {
                                $q->orWhere('meta_value', 'LIKE', '%"provider_identifier":"' . $identifier . '"%');
                            }
                        });
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($contact) use ($providers) {

                    // Map metas and replace numeric provider with full provider object
                    $meta = $contact->metas->mapWithKeys(function ($m) use ($providers) {
                        $value = $m->meta_value;

                        // Decode JSON if present
                        if (is_string($value) && json_decode($value, true) !== null) {
                            $value = json_decode($value, true);
                        }

                        // Replace provider inside profile_details
                        if ($m->meta_key === 'profile_details' && isset($value['provider'])) {
                            $providerId = is_numeric($value['provider']) ? (int)$value['provider'] : $value['provider'];

                            if (isset($providers[$providerId])) {
                                $provider = $providers[$providerId];
                                $value['provider'] = [
                                    'id'   => $provider['id'],
                                    'name' => $provider['name'],
                                    'icon' => $provider['meta']['icon'] ?? null,
                                ];
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

            Log::info('Contacts fetched successfully.', [
                'user_id' => $userId,
                'contacts_count' => $contacts->count()
            ]);

            return response()->json([
                'success' => true,
                'data'    => $contacts,
                'meta'    => [
                    'total' => $contacts->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contacts', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load contacts',
                'error' => app()->environment('local') ? $e->getMessage() : null
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