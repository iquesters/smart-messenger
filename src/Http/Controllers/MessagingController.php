<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\Integration\Models\Integration;
use Iquesters\SmartMessenger\Services\AgentResolverService;

class MessagingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        /**
         * ---------------------------------------------------------
         * Load organisation IDs for current user (via trait)
         * ---------------------------------------------------------
         */
        $organisationIds = collect();

        if (method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        /**
         * ---------------------------------------------------------
         * Load messaging profiles
         * - User owned channels
         * - Organisation shared channels
         * ---------------------------------------------------------
         */
        $profiles = Channel::query()
            ->where('created_by', $user->id)

            // ✅ Organisation access via trait (pivot)
            ->orWhere(function ($query) use ($organisationIds) {
                if (
                    $organisationIds->isNotEmpty() &&
                    method_exists(Channel::class, 'organisations')
                ) {
                    $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with(['metas', 'provider'])
            ->get();

        Log::info('Fetched profiles (user + organisation)', [
            'user_id' => $user->id,
            'count' => $profiles->count()
        ]);

        /**
         * ---------------------------------------------------------
         * Extract WhatsApp numbers from profiles
         * ---------------------------------------------------------
         */
        $numbers = [];
        foreach ($profiles as $profile) {
            $phone   = $profile->getMeta('whatsapp_number');
            $country = $profile->getMeta('country_code');

            if ($phone) {
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number'     => ($country ?? '') . $phone,
                    'name'       => $profile->name,
                    'icon'       => $profile->provider?->getMeta('icon') ?? ''
                ];
            }
        }

        /**
         * ---------------------------------------------------------
         * Selected number / contact
         * ---------------------------------------------------------
         */
        $selectedNumber  = $request->get('number');
        $selectedContact = $request->get('contact');

        if (!$selectedNumber && count($numbers) > 0) {
            $selectedNumber = $numbers[0]['number'];
        }

        $messages = collect();
        $contacts = [];
        $allMessages = collect();
        $profile = null;
        $integrationUid = '';
        $hasMoreMessages = false;
        $oldestMessageId = null;
        $contactsLookup = [];
        $selectedContactName = null;

        /**
         * ---------------------------------------------------------
         * Load profile + provider identifier
         * ---------------------------------------------------------
         */
        if ($selectedNumber) {

            $profile = $profiles->first(function ($p) use ($selectedNumber) {
                return ($p->getMeta('country_code') ?? '') . $p->getMeta('whatsapp_number') === $selectedNumber;
            });
            if ($profile) {
                $integrationUid = $this->getWooCommerceIntegrationUidFromChannel($profile);

                $agentData = app(AgentResolverService::class)->resolvePhones($profile);

                $allAgentPhones = $agentData['all'] ?? [];
                $activeAgentPhones = $agentData['active'] ?? [];
                
                Log::debug('Agent phones resolved', [
                    'profile_id' => $profile->id,
                    'all_agents_count' => count($allAgentPhones),
                    'active_agents_count' => count($activeAgentPhones)
                ]);
                
                /**
                 * Provider identifier (whatsapp_phone_number_id)
                 */
                $providerIdentifier = $profile->metas
                    ->where('meta_key', 'whatsapp_phone_number_id')
                    ->pluck('meta_value')
                    ->first();

                /**
                 * -------------------------------------------------
                 * Load contacts ONLY for this profile
                 * -------------------------------------------------
                 */
                if ($providerIdentifier) {
                    $channelUid = $profile->uid; // or however you store unique channel id

                $contactsLookup = Contact::with(['metas' => function ($q) use ($channelUid) {
                        $q->where('meta_key', $channelUid);
                    }])
                    ->get()
                    ->mapWithKeys(function ($contact) use ($channelUid) {

                        $meta = $contact->metas
                            ->where('meta_key', $channelUid)
                            ->first();

                        if (!$meta) {
                            return [];
                        }

                        $data = json_decode($meta->meta_value, true);

                        if (!$data || empty($data['identifier'])) {
                            return [];
                        }

                        return [
                            $data['identifier'] => $data['name'] ?? $contact->name
                        ];
                    })
                    ->toArray();
                }

                /**
                 * -------------------------------------------------
                 * All messages (table view)
                 * -------------------------------------------------
                 */
                $allMessages = Message::where('channel_id', $profile->id)
                    ->orderBy('timestamp', 'desc')
                    ->get();

                /**
                 * -------------------------------------------------
                 * Build contacts list from messages
                 * -------------------------------------------------
                 */
                $contacts = Message::where('channel_id', $profile->id)
                    ->select('from', 'to', 'content', 'timestamp')
                    ->orderBy('timestamp', 'desc')
                    ->get()
                    ->groupBy(function ($msg) use ($selectedNumber) {
                        return $msg->from === $selectedNumber ? $msg->to : $msg->from;
                    })
                    ->map(function ($msgs, $contactNumber) use ($profile, $contactsLookup, $allAgentPhones, $activeAgentPhones) {

                        $lastMsg = $msgs->first();
                        $normalized = ltrim($contactNumber, '+');

                        $isAgent = in_array($normalized, $allAgentPhones);
                        $isActiveAgent = in_array($normalized, $activeAgentPhones);
                        Log::debug('Contact is agent?', ['is_active_agent' => $isActiveAgent, 'is_agent' => $isAgent]);
                        return [
                            'number'         => $contactNumber,
                            'name'           => $contactsLookup[$contactNumber] ?? $contactNumber,
                            'provider_name'  => $profile->provider?->value ?? 'Unknown',
                            'provider_icon'  => $profile->provider?->getMeta('icon') ?? '',
                            'last_message'   => $lastMsg->content,
                            'last_timestamp' => $lastMsg->timestamp,
                            'is_agent'        => $isAgent,
                            'is_active_agent' => $isActiveAgent,
                        ];
                    })
                    ->sortByDesc('last_timestamp')
                    ->values()
                    ->toArray();

                /**
                 * -------------------------------------------------
                 * Selected contact messages
                 * -------------------------------------------------
                 */
                if ($selectedContact) {

                    $selectedContactName = $contactsLookup[$selectedContact] ?? $selectedContact;

                    $messageQuery = Message::where('channel_id', $profile->id)
                        ->where(function ($query) use ($selectedContact) {
                            $query->where('from', $selectedContact)
                                ->orWhere('to', $selectedContact);
                        });

                    $totalMessages = (clone $messageQuery)->count();

                    $messages = $messageQuery
                        ->with(['metas', 'integration.supportedIntegration', 'creator'])
                        ->orderBy('timestamp', 'desc')
                        ->orderBy('id', 'desc')
                        ->limit(10)
                        ->get()
                        ->reverse()
                        ->values();

                    $hasMoreMessages = $totalMessages > $messages->count();
                    $oldestMessageId = $messages->first()?->id;
                }
            }
        }
        /**
         * ---------------------------------------------------------
         * Render view
         * ---------------------------------------------------------
         */
        return view('smartmessenger::messages.index', [
            'numbers'             => $numbers,
            'selectedNumber'      => $selectedNumber,
            'contacts'            => $contacts,
            'selectedContact'     => $selectedContact,
            'selectedContactName' => $selectedContactName,
            'messages'            => $messages,
            'allMessages'         => $allMessages,
            'profile'             => $profile,
            'integrationUid'      => $integrationUid,
            'hasMoreMessages'     => $hasMoreMessages,
            'oldestMessageId'     => $oldestMessageId,
        ]);
    }

    public function loadOlderMessages(Request $request): JsonResponse
    {
        $request->validate([
            'profile_id' => 'required|integer|exists:channels,id',
            'contact' => 'required|string',
            'before_id' => 'required|integer|exists:messages,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth()->user();
        $profile = $this->resolveAccessibleProfile((int) $request->input('profile_id'), $user);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found or access denied',
            ], 403);
        }

        $selectedContact = $request->input('contact');
        $limit = (int) $request->input('limit', 10);

        $baseQuery = Message::where('channel_id', $profile->id)
            ->where(function ($query) use ($selectedContact) {
                $query->where('from', $selectedContact)
                    ->orWhere('to', $selectedContact);
            });

        $beforeMessage = (clone $baseQuery)->where('id', (int) $request->input('before_id'))->first();
        if (!$beforeMessage) {
            return response()->json([
                'success' => false,
                'message' => 'Reference message not found',
            ], 404);
        }

        $olderMessages = (clone $baseQuery)
            ->where(function ($query) use ($beforeMessage) {
                $query->where('timestamp', '<', $beforeMessage->timestamp)
                    ->orWhere(function ($q) use ($beforeMessage) {
                        $q->where('timestamp', '=', $beforeMessage->timestamp)
                            ->where('id', '<', $beforeMessage->id);
                    });
            })
            ->with(['metas', 'integration.supportedIntegration', 'creator'])
            ->orderBy('timestamp', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $oldestLoaded = $olderMessages->first();
        $hasMore = false;
        if ($oldestLoaded) {
            $hasMore = (clone $baseQuery)
                ->where(function ($query) use ($oldestLoaded) {
                    $query->where('timestamp', '<', $oldestLoaded->timestamp)
                        ->orWhere(function ($q) use ($oldestLoaded) {
                            $q->where('timestamp', '=', $oldestLoaded->timestamp)
                                ->where('id', '<', $oldestLoaded->id);
                        });
                })
                ->exists();
        }

        $integrationUid = $this->getWooCommerceIntegrationUidFromChannel($profile);
        $isSuperAdmin = $this->isUserSuperAdmin($user);
        $selectedNumber = ($profile->getMeta('country_code') ?? '') . $profile->getMeta('whatsapp_number');

        $html = view('smartmessenger::messages.partials.chat.messages-list', [
            'messages' => $olderMessages,
            'selectedNumber' => $selectedNumber,
            'isSuperAdmin' => $isSuperAdmin,
            'integrationUid' => $integrationUid,
        ])->render();

        return response()->json([
            'success' => true,
            'html' => $html,
            'has_more' => $hasMore,
            'oldest_id' => $olderMessages->first()?->id,
        ]);
    }
    
    
    /** 
     * @todo
     * For now we send woocommerce integration uid,
     * but in future we have to detect the ids from workflow and send dynnamically 
     */
    private function getWooCommerceIntegrationUidFromChannel(Channel $channel): string
    {
        $context = [
            'channel_id' => $channel->id,
            'channel_uid' => $channel->uid,
        ];

        try {
            $organisation = $channel->organisations()->first();

            if (!$organisation) {
                Log::warning('No organisation linked to selected channel', $context);
                return '';
            }

            $integrations = $organisation
                ->models(Integration::class)
                ->get()
                ->load(['supportedIntegration', 'metas']);

            $integration = $integrations->first(function ($integration) {
                $isWoo = strtolower((string) optional($integration->supportedIntegration)->name) === 'woocommerce';
                $isActive = strtolower((string) ($integration->status ?? '')) === 'active';
                return $isWoo && $isActive;
            });

            if (!$integration) {
                return '';
            }

            return (string) ($integration->uid ?? '');
        } catch (\Throwable $e) {
            Log::error('Failed to resolve WooCommerce integration UID for channel', $context + [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function resolveAccessibleProfile(int $profileId, $user): ?Channel
    {
        $organisationIds = collect();

        if ($user && method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        return Channel::where('id', $profileId)
            ->where(function ($query) use ($user, $organisationIds) {
                $query->where('created_by', $user->id);

                if (
                    $organisationIds->isNotEmpty() &&
                    method_exists(Channel::class, 'organisations')
                ) {
                    $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with('metas')
            ->first();
    }

    private function isUserSuperAdmin($user): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('super-admin');
        }

        if (method_exists($user, 'roles')) {
            return $user->roles()->where('name', 'super-admin')->exists();
        }

        return isset($user->role) && $user->role === 'super-admin';
    }

    /**
     * ---------------------------------------------------------
     * Send message
     * ---------------------------------------------------------
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'profile_id' => 'required|exists:channels,id',
            'to' => 'required|string',
            'message' => 'required|string'
        ]);

        $user = auth()->user();

        /**
         * ---------------------------------------------------------
         * Ensure user can access this channel
         * (user-owned OR organisation-owned)
         * ---------------------------------------------------------
         */
        $organisationIds = collect();

        if (method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        $profile = Channel::where('id', $request->profile_id)
            ->where(function ($query) use ($user, $organisationIds) {

                // User owned
                $query->where('created_by', $user->id);

                // Organisation owned
                if (
                    $organisationIds->isNotEmpty() &&
                    method_exists(Channel::class, 'organisations')
                ) {
                    $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with('metas')
            ->firstOrFail();

        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            return response()->json(['error' => 'WhatsApp credentials missing'], 422);
        }

        try {
            /**
             * 1️⃣ Send to WhatsApp
             */
            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $request->to,
                    'type' => 'text',
                    'text' => [
                        'body' => $request->message
                    ]
                ]
            );

            if (!$response->successful()) {
                Log::error('WhatsApp send failed', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);

                return response()->json(['error' => 'WhatsApp send failed'], 500);
            }
            $waMessageId = data_get($response->json(), 'messages.0.id');
            /**
             * 2️⃣ Save message locally
             */
            $message = Message::create([
                'channel_id' => $profile->id,
                'message_id'   => $waMessageId, 
                'from' => ($profile->getMeta('country_code') ?? '') . $profile->getMeta('whatsapp_number'),
                'to' => $request->to,
                'message_type' => 'text',
                'content' => $request->message,
                'timestamp' => now(),
                'status' => Constants::SENT,
                'raw_payload' => $response->json(),
                'created_by' => $user->id,
            ]);

            return response()->json([
                'status' => Constants::SUCCESS,
                'message' => $message
            ]);

        } catch (\Throwable $e) {
            Log::error('Send message exception', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => Constants::ERROR,
                'message' => 'Failed to send message'
            ], 500);
        }
    }
}
