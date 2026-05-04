<?php

namespace Iquesters\SmartMessenger\Services;

use Throwable;
use Iquesters\Integration\Models\Integration;
use Iquesters\Integration\Models\SupportedIntegration;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\Foundation\System\Traits\Loggable;

class ChatbotIntegrationResolverService
{
    use Loggable;

    public function resolveUidFromMessage(Message $message): string
    {
        return $this->resolveUidFromChannel($message->channel);
    }

    public function resolveUidFromChannel(?Channel $channel): string
    {
        return (string) ($this->resolveActiveChatbotIntegrationFromChannel($channel)?->uid ?? '');
    }

    public function resolveIdFromMessage(?Message $message): ?int
    {
        return $message ? $this->resolveIdFromChannel($message->channel) : null;
    }

    public function resolveIdFromChannel(?Channel $channel): ?int
    {
        return $this->resolveActiveChatbotIntegrationFromChannel($channel)?->id;
    }

    public function getApiToken(): ?string
    {
        return $this->resolveSupportedChatbotIntegration()?->getMeta(Constants::CHATBOT_API_TOKEN);
    }

    public function getApiUrl(): string
    {
        return (string) ($this->resolveSupportedChatbotIntegration()?->getMeta(Constants::CHATBOT_API_URL) ?? '');
    }

    private function resolveActiveChatbotIntegrationFromChannel(?Channel $channel): ?Integration
    {
        $context = [
            'channel_id' => $channel?->id,
            'channel_uid' => $channel?->uid,
        ];

        try {
            if (!$channel) {
                $this->logWarning('Cannot resolve chatbot integration: channel missing' . $this->ctx($context));
                return null;
            }

            $organisation = $channel->organisations()->first();

            if (!$organisation) {
                $this->logWarning('No organisation linked to channel for chatbot integration resolution' . $this->ctx($context));
                return null;
            }

            $integrations = $organisation
                ->models(Integration::class)
                ->get()
                ->load(['supportedIntegration', 'metas']);

            $integration = $integrations->first(function ($integration) {
                return optional($integration->supportedIntegration)->name === Constants::GAUTAMS_CHATBOT
                    && strtolower((string) ($integration->status ?? '')) === Constants::ACTIVE;
            });

            if (!$integration) {
                $this->logWarning('No active chatbot integration found for channel' . $this->ctx($context + [
                    'organisation_id' => $organisation->id,
                ]));
                return null;
            }

            $this->logInfo('Chatbot integration resolved successfully' . $this->ctx($context + [
                'organisation_id' => $organisation->id,
                'integration_id' => $integration->id,
                'integration_uid' => $integration->uid,
            ]));

            return $integration;
        } catch (Throwable $e) {
            $this->logError('Chatbot integration resolution failed' . $this->ctx($context + [
                'error' => $e->getMessage(),
            ]));

            return null;
        }
    }

    private function resolveSupportedChatbotIntegration(): ?SupportedIntegration
    {
        try {
            $supportedIntegration = SupportedIntegration::query()
                ->with('metas')
                ->where('name', Constants::GAUTAMS_CHATBOT)
                ->first();

            if (!$supportedIntegration) {
                $this->logWarning('Supported chatbot integration definition not found' . $this->ctx([
                    'supported_integration_name' => Constants::GAUTAMS_CHATBOT,
                ]));
                return null;
            }

            return $supportedIntegration;
        } catch (Throwable $e) {
            $this->logError('Failed resolving supported chatbot integration definition' . $this->ctx([
                'supported_integration_name' => Constants::GAUTAMS_CHATBOT,
                'error' => $e->getMessage(),
            ]));

            return null;
        }
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
