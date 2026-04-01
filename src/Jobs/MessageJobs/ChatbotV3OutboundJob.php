<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\Integration\Models\Integration;
use Iquesters\SmartMessenger\Models\Message;

class ChatbotV3OutboundJob extends BaseJob
{
    protected array $payload = [];
    protected ?Message $inboundMessage = null;
    protected array $chatbotResponse = [];
    protected ?int $integrationId = null;

    protected function initialize(...$arguments): void
    {
        [$this->payload] = $arguments;

        $this->queue = 'ChatbotV3OutboundJob';
    }

    public function process(): void
    {
        $messageId = $this->payload['message_id'] ?? null;
        $this->chatbotResponse = $this->payload['chatbot_response'] ?? [];

        $this->logMethodStart($this->ctx([
            'queue_message_id' => $messageId,
            'integration_uid' => $this->payload['integration_id'] ?? null,
            'session_id' => $this->chatbotResponse['session_id'] ?? null,
        ]));

        try {
            if (!$messageId) {
                throw new \InvalidArgumentException('Missing message_id in ChatbotV3OutboundJob payload');
            }

            if (!is_array($this->chatbotResponse) || empty($this->chatbotResponse)) {
                throw new \InvalidArgumentException('Missing chatbot_response in ChatbotV3OutboundJob payload');
            }

            $this->inboundMessage = Message::query()
                ->where('message_id', $messageId)
                ->first();

            if (!$this->inboundMessage) {
                throw new \RuntimeException("Inbound message not found for message_id={$messageId}");
            }

            $this->integrationId = $this->resolveChatbotIntegrationIdFromInboundMessage();

            $this->logInfo('Chatbot V3 outbound payload resolved' . $this->ctx([
                'queue_message_id' => $messageId,
                'inbound_message_id' => $this->inboundMessage->id,
                'integration_id' => $this->integrationId,
                'messages_count' => count($this->chatbotResponse['messages'] ?? []),
                'actions_count' => count($this->chatbotResponse['actions'] ?? []),
                'tool_payloads_count' => count($this->chatbotResponse['tool_payloads'] ?? []),
            ]));

            ProcessChatbotResponseJob::dispatch(
                $this->inboundMessage,
                $this->chatbotResponse,
                $this->integrationId
            );

            $this->logInfo('Dispatched ProcessChatbotResponseJob from ChatbotV3OutboundJob' . $this->ctx([
                'queue_message_id' => $messageId,
                'inbound_message_id' => $this->inboundMessage->id,
                'integration_id' => $this->integrationId,
            ]));
        } catch (\Throwable $e) {
            $this->logError('ChatbotV3OutboundJob failed' . $this->ctx([
                'queue_message_id' => $messageId,
                'integration_uid' => $this->payload['integration_id'] ?? null,
                'session_id' => $this->chatbotResponse['session_id'] ?? null,
                'inbound_message_id' => $this->inboundMessage?->id,
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'queue_message_id' => $messageId,
                'inbound_message_id' => $this->inboundMessage?->id,
            ]));
        }
    }

    private function resolveChatbotIntegrationIdFromInboundMessage(): ?int
    {
        try {
            $channel = $this->inboundMessage?->channel;
            $organisation = $channel?->organisations()->first();

            if (!$organisation) {
                $this->logWarning('No organisation found when resolving chatbot integration from inbound message' . $this->ctx([
                    'queue_message_id' => $this->payload['message_id'] ?? null,
                    'inbound_message_id' => $this->inboundMessage?->id,
                    'channel_id' => $channel?->id,
                ]));

                return null;
            }

            $integration = $organisation
                ->models(Integration::class)
                ->get()
                ->first(function ($integration) {
                    return optional($integration->supportedIntegration)->name === Constants::GAUTAMS_CHATBOT
                        && strtolower($integration->status ?? '') === 'active';
                });

            if (!$integration) {
                $this->logWarning('No active gautams-chatbot integration found for inbound message context' . $this->ctx([
                    'queue_message_id' => $this->payload['message_id'] ?? null,
                    'inbound_message_id' => $this->inboundMessage?->id,
                    'organisation_id' => $organisation->id,
                ]));

                return null;
            }

            $this->logInfo('Resolved chatbot integration from inbound message context' . $this->ctx([
                'queue_message_id' => $this->payload['message_id'] ?? null,
                'inbound_message_id' => $this->inboundMessage?->id,
                'organisation_id' => $organisation->id,
                'integration_id' => $integration->id,
                'integration_uid' => $integration->uid,
            ]));

            return $integration->id;
        } catch (\Throwable $e) {
            $this->logError('Failed resolving chatbot integration from inbound message context' . $this->ctx([
                'queue_message_id' => $this->payload['message_id'] ?? null,
                'inbound_message_id' => $this->inboundMessage?->id,
                'error' => $e->getMessage(),
            ]));

            return null;
        }
    }
}
