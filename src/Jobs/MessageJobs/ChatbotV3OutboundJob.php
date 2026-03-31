<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
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

            $this->integrationId = $this->resolveIntegrationId(
                $this->payload['integration_id'] ?? null
            );

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

    private function resolveIntegrationId(?string $integrationReference): ?int
    {
        if (!$integrationReference) {
            return null;
        }

        if (is_numeric($integrationReference)) {
            return (int) $integrationReference;
        }

        return Integration::query()
            ->where('uid', $integrationReference)
            ->value('id');
    }
}