<?php

namespace Iquesters\SmartMessenger\Services;

use Iquesters\SmartMessenger\Models\Message;
use Illuminate\Support\Collection;
use Iquesters\Organisation\Models\Team;
use App\Models\User;
use Iquesters\Integration\Models\Integration;
use Iquesters\Foundation\System\Traits\Loggable;

class AgentResolverService
{
    use Loggable;

    /**
     * Resolve active agent phone numbers for a channel
     */
    public function resolvePhones($channel): array
    {
        $this->logMethodStart("Resolving agent phones for channel {$channel->id}");

        try {
            $integrationUid = $this->resolveIntegrationUidFromChannel($channel);

            // 1️⃣ Get meta values
            $teamIds = $channel->getMeta('support_team_ids') ?? [];
            $userIds = $channel->getMeta('support_user_ids') ?? [];

            $teamIds = is_array($teamIds) ? $teamIds : json_decode($teamIds, true) ?? [];
            $userIds = is_array($userIds) ? $userIds : json_decode($userIds, true) ?? [];

            $this->logDebug("Meta extracted: teams=" . count($teamIds) . ", users=" . count($userIds));

            // 2️⃣ Expand teams → users
            if (!empty($teamIds)) {
                $teamUsers = Team::whereIn('id', $teamIds)
                    ->with('users:id,phone')
                    ->get()
                    ->pluck('users')
                    ->flatten()
                    ->pluck('id')
                    ->toArray();

                $this->logDebug("Expanded team members: " . count($teamUsers));

                $userIds = array_merge($userIds, $teamUsers);
            }

            // 3️⃣ Unique users
            $userIds = array_unique($userIds);

            if (empty($userIds)) {
                $this->logWarning("No users resolved from teams/meta");
                $this->logMethodEnd("No agents found");
                return [];
            }

            $this->logDebug("Unique users resolved: " . count($userIds));

            // 4️⃣ Get phones
            $phones = User::whereIn('id', $userIds)
                ->whereNotNull('phone')
                ->pluck('phone')
                ->unique()
                ->values();

            if ($phones->isEmpty()) {
                $this->logWarning("Users found but no phone numbers available");
                $this->logMethodEnd("No phones found");
                return [];
            }

            $this->logDebug("Phones resolved: " . $phones->count());

            // 5️⃣ Filter by WhatsApp 24h session window
            $activePhones = $this->filterActiveWhatsAppSessions($channel, $phones);

            $this->logInfo("Active agent phones resolved: " . count($activePhones));
            $this->logMethodEnd("Agent resolution complete");

            return [
                'all'    => $phones->map(fn($p) => ltrim($p, '+'))->toArray(),
                'active' => $activePhones,
            ];

        } catch (\Throwable $e) {
            $this->logError(sprintf(
                'Agent resolution failed | channel_id=%s integration_uid=%s error=%s',
                $channel->id ?? 'null',
                $integrationUid ?? 'null',
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * Only return phones that have a message within last 24h
     */
    protected function filterActiveWhatsAppSessions($channel, Collection $phones): array
    {
        $this->logMethodStart("Filtering active WhatsApp sessions");

        try {

            // Normalize phones → create variants with and without +
            $normalized = collect();

            foreach ($phones as $phone) {
                $clean = ltrim($phone, '+');

                $normalized->push($clean);      // without +
                $normalized->push('+' . $clean); // with +
            }

            $normalized = $normalized->unique()->values();

            $this->logDebug("Normalized phones: " . json_encode($normalized->toArray()));

            $matches = Message::where('channel_id', $channel->id)
                ->whereIn('from', $normalized)
                ->whereRaw("timestamp >= NOW() - INTERVAL 24 HOUR")
                ->get(['id', 'from', 'timestamp']);

            $this->logDebug("Matched rows: " . $matches->count());

            if ($matches->isNotEmpty()) {
                $this->logDebug("Matched data: " . json_encode($matches->toArray()));
            }

            $active = $matches
                ->pluck('from')
                ->map(fn($p) => ltrim($p, '+')) // return normalized format
                ->unique()
                ->values()
                ->toArray();

            $this->logInfo("Active WhatsApp sessions: " . count($active));

            $this->logMethodEnd("Session filtering complete");

            return $active;

        } catch (\Throwable $e) {
            $this->logError("Session filtering failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function resolveActiveIntegrationFromChannel($channel): ?Integration
    {
        $context = [
            'channel_id' => $channel->id ?? null,
        ];

        try {
            $organisation = $channel->organisations()->first();

            if (!$organisation) {
                $this->logWarning('No organisation linked to channel for agent resolution' . $this->formatContext($context));
                return null;
            }

            $integrations = $organisation
                ->models(Integration::class)
                ->get()
                ->load(['supportedIntegration', 'metas']);

            $integration = $integrations->first(function ($integration) {
                $isWoo = optional($integration->supportedIntegration)->name === 'woocommerce';
                $isActive = strtolower($integration->status ?? '') === 'active';

                return $isWoo && $isActive;
            });

            if (!$integration) {
                $this->logWarning('No active WooCommerce integration found for agent resolution' . $this->formatContext($context + [
                    'organisation_id' => $organisation->id,
                    'available_integrations' => $integrations->map(fn ($i) => [
                        'id' => $i->id,
                        'uid' => $i->uid,
                        'supported' => optional($i->supportedIntegration)->name,
                        'active' => $i->getMeta('is_active'),
                    ])->values()->toArray(),
                ]));
                return null;
            }

            return $integration;
        } catch (\Throwable $e) {
            $this->logError('Integration UID resolution failed for agent resolution' . $this->formatContext($context + [
                'error' => $e->getMessage(),
            ]));

            return null;
        }
    }

    protected function resolveIntegrationUidFromChannel($channel): string
    {
        $integration = $this->resolveActiveIntegrationFromChannel($channel);

        return (string) ($integration?->uid ?? '');
    }

    protected function formatContext(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

}
