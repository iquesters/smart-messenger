<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Iquesters\Integration\Models\Integration;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Throwable;

class MessagingDataService
{
    public function __construct(
        protected AgentResolverService $agentResolverService,
        protected ChatSessionLookupService $chatSessionLookupService,
        protected HumanHandoverStateResolver $humanHandoverStateResolver,
        protected ChatbotIntegrationResolverService $chatbotIntegrationResolverService,
    ) {
    }

    public function buildInboxData($user, array $filters): array
    {
        $profiles = $this->getAccessibleProfiles($user);
        $numbers = $this->buildProfileFilters($profiles);
        $selectedNumber = $filters['number'] ?? null;
        $selectedContact = $filters['contact'] ?? null;

        if (!$selectedNumber && count($numbers) > 0) {
            $selectedNumber = $numbers[0]['number'];
        }

        $data = [
            'numbers' => $numbers,
            'selectedNumber' => $selectedNumber,
            'contacts' => [],
            'selectedContact' => $selectedContact,
            'selectedContactName' => null,
            'selectedContactUid' => null,
            'selectedContactHandoverState' => $this->emptyHandoverState(),
            'chatbotHumanHandoverEnabled' => false,
            'messages' => collect(),
            'allMessages' => collect(),
            'profile' => null,
            'integrationUid' => '',
            'chatbotIntegrationUid' => '',
            'hasMoreMessages' => false,
            'oldestMessageId' => null,
        ];

        if (!$selectedNumber) {
            return $data;
        }

        $profile = $profiles->first(fn ($item) => $this->getProfileMessagingIdentifier($item) === $selectedNumber);
        if (!$profile) {
            return $data;
        }

        $chatbotHumanHandoverEnabled = $this->chatbotIntegrationResolverService
            ->isHumanHandoverEnabledFromChannel($profile);

        $contactsMetaLookup = $this->buildContactsMetaLookup($profile);
        $contactsLookup = collect($contactsMetaLookup)
            ->mapWithKeys(fn ($contactMeta, $identifier) => [$identifier => $contactMeta['name'] ?? $identifier])
            ->toArray();

        $agentData = $this->agentResolverService->resolvePhones($profile);
        $allAgentPhones = $agentData['all'] ?? [];
        $activeAgentPhones = $agentData['active'] ?? [];
        $chatbotIntegrationUid = $this->chatbotIntegrationResolverService->resolveUidFromChannel($profile);

        Log::debug('Agent phones resolved', [
            'profile_id' => $profile->id,
            'all_agents_count' => count($allAgentPhones),
            'active_agents_count' => count($activeAgentPhones),
        ]);

        $data['profile'] = $profile;
        $data['integrationUid'] = $this->getWooCommerceIntegrationUidFromChannel($profile);
        $data['chatbotIntegrationUid'] = $chatbotIntegrationUid;
        $data['chatbotHumanHandoverEnabled'] = $chatbotHumanHandoverEnabled;
        $data['allMessages'] = Message::where('channel_id', $profile->id)
            ->orderBy('timestamp', 'desc')
            ->get();
        $data['contacts'] = Message::where('channel_id', $profile->id)
            ->select('from', 'to', 'content', 'timestamp', 'message_type')
            ->orderBy('timestamp', 'desc')
            ->get()
            ->groupBy(fn ($msg) => $msg->from === $selectedNumber ? $msg->to : $msg->from)
            ->map(function ($msgs, $contactNumber) use (
                $profile,
                $contactsLookup,
                $contactsMetaLookup,
                $allAgentPhones,
                $activeAgentPhones,
                $chatbotIntegrationUid,
                $chatbotHumanHandoverEnabled
            ) {
                $lastMsg = $msgs->first();
                $normalized = ltrim($contactNumber, '+');
                $contactUid = $contactsMetaLookup[$contactNumber]['uid'] ?? null;
                $handoverState = $chatbotHumanHandoverEnabled
                    ? $this->resolveSelectedContactHandoverState($contactUid, $chatbotIntegrationUid)
                    : $this->emptyHandoverState();

                return [
                    'number' => $contactNumber,
                    'name' => $contactsLookup[$contactNumber] ?? $contactNumber,
                    'provider_name' => $profile->provider?->value ?? 'Unknown',
                    'provider_icon' => $profile->provider?->getMeta('icon') ?? '',
                    'last_message' => $lastMsg->content,
                    'last_message_type' => $lastMsg->message_type,
                    'last_timestamp' => $lastMsg->timestamp,
                    'is_agent' => in_array($normalized, $allAgentPhones),
                    'is_active_agent' => in_array($normalized, $activeAgentPhones),
                    'human_handover_active' => (bool) ($handoverState['active'] ?? false),
                ];
            })
            ->sortByDesc('last_timestamp')
            ->values()
            ->toArray();

        if (!$selectedContact) {
            return $data;
        }

        $selectedContactModel = $this->resolveContactForProfile($profile, $selectedContact);
        $messageQuery = Message::where('channel_id', $profile->id)
            ->where(function ($query) use ($selectedContact) {
                $query->where('from', $selectedContact)
                    ->orWhere('to', $selectedContact);
            });

        $messages = $messageQuery
            ->with(['metas', 'integration.supportedIntegration', 'creator'])
            ->orderBy('timestamp', 'desc')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        $data['selectedContactName'] = $contactsLookup[$selectedContact] ?? $selectedContact;
        $data['selectedContactUid'] = $selectedContactModel?->uid;
        $data['selectedContactHandoverState'] = $chatbotHumanHandoverEnabled
            ? $this->resolveSelectedContactHandoverState($selectedContactModel?->uid, $chatbotIntegrationUid)
            : $this->emptyHandoverState();
        $data['messages'] = $messages;
        $data['hasMoreMessages'] = (clone $messageQuery)->count() > $messages->count();
        $data['oldestMessageId'] = $messages->first()?->id;

        return $data;
    }

    public function resolveAccessibleProfile(int $profileId, $user): ?Channel
    {
        $organisationIds = $this->getOrganisationIdsForUser($user);

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
            ->with(['metas', 'provider'])
            ->first();
    }

    public function buildOlderMessagesData(Channel $profile, string $selectedContact, int $beforeId, int $limit, $user): array
    {
        $baseQuery = Message::where('channel_id', $profile->id)
            ->where(function ($query) use ($selectedContact) {
                $query->where('from', $selectedContact)
                    ->orWhere('to', $selectedContact);
            });

        $beforeMessage = (clone $baseQuery)->where('id', $beforeId)->first();
        if (!$beforeMessage) {
            return [
                'success' => false,
                'message' => 'Reference message not found',
                'status' => 404,
            ];
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

        return [
            'success' => true,
            'messages' => $olderMessages,
            'has_more' => $hasMore,
            'oldest_id' => $olderMessages->first()?->id,
            'integrationUid' => $this->getWooCommerceIntegrationUidFromChannel($profile),
            'selectedNumber' => $this->getProfileMessagingIdentifier($profile) ?? '',
            'isSuperAdmin' => $this->isUserSuperAdmin($user),
        ];
    }

    public function getProfileMessagingIdentifier(Channel $channel): ?string
    {
        $providerSlug = strtolower((string) ($channel->provider?->small_name ?? ''));

        return match ($providerSlug) {
            'telegram' => $channel->getMeta('telegram_bot_username'),
            default => ($channel->getMeta('country_code') ?? '') . ($channel->getMeta('whatsapp_number') ?? ''),
        };
    }

    public function isUserSuperAdmin($user): bool
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

    protected function getAccessibleProfiles($user): Collection
    {
        $organisationIds = $this->getOrganisationIdsForUser($user);

        $profiles = Channel::query()
            ->where('created_by', $user->id)
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
            'count' => $profiles->count(),
        ]);

        return $profiles;
    }

    protected function buildProfileFilters(Collection $profiles): array
    {
        $numbers = [];

        foreach ($profiles as $profile) {
            $profileIdentifier = $this->getProfileMessagingIdentifier($profile);

            if ($profileIdentifier) {
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number' => $profileIdentifier,
                    'name' => $profile->name,
                    'icon' => $profile->provider?->getMeta('icon') ?? '',
                ];
            }
        }

        return $numbers;
    }

    protected function buildContactsMetaLookup(Channel $profile): array
    {
        $channelUid = $profile->uid;

        return Contact::with(['metas' => function ($q) use ($channelUid) {
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
                    $data['identifier'] => [
                        'name' => $data['name'] ?? $contact->name,
                        'uid' => $contact->uid,
                    ],
                ];
            })
            ->toArray();
    }

    protected function resolveContactForProfile(Channel $profile, string $identifier): ?Contact
    {
        $organisationIds = $profile->organisations()->pluck('organisations.id');

        if ($organisationIds->isEmpty()) {
            return null;
        }

        return Contact::query()
            ->where('identifier', $identifier)
            ->whereHas('organisations', function ($query) use ($organisationIds) {
                $query->whereIn('organisations.id', $organisationIds);
            })
            ->first();
    }

    protected function resolveSelectedContactHandoverState(?string $contactUid, ?string $chatbotIntegrationUid): array
    {
        if (empty($contactUid) || empty($chatbotIntegrationUid)) {
            return $this->emptyHandoverState();
        }

        try {
            $session = $this->chatSessionLookupService->findLatestActive($contactUid, $chatbotIntegrationUid);

            if (!$session) {
                return $this->emptyHandoverState();
            }

            return $this->humanHandoverStateResolver->resolve($session->context_json) + [
                'session_id' => $session->session_id,
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to resolve selected contact handover state for messaging UI', [
                'contact_uid' => $contactUid,
                'chatbot_integration_uid' => $chatbotIntegrationUid,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyHandoverState();
        }
    }

    protected function emptyHandoverState(): array
    {
        return [
            'session_id' => null,
            'active' => false,
            'hand_over_time' => null,
            'reason' => null,
            'status' => null,
            'ended_utc' => null,
            'ended_by' => null,
            'raw_path' => null,
        ];
    }

    protected function getWooCommerceIntegrationUidFromChannel(Channel $channel): string
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

            return (string) ($integration->uid ?? '');
        } catch (Throwable $e) {
            Log::error('Failed to resolve WooCommerce integration UID for channel', $context + [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    protected function getOrganisationIdsForUser($user): Collection
    {
        if ($user && method_exists($user, 'organisations')) {
            return $user->organisations()->pluck('organisations.id');
        }

        return collect();
    }
}
