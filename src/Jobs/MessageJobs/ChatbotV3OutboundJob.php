<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;

class ChatbotV3OutboundJob extends BaseJob
{
    protected array $payload = [];
    protected ?Message $inboundMessage = null;
    protected array $chatbotResponse = [];
    protected ?int $integrationId = null;

    /**
     * `progress` or `final`, as chatbot-core's event sink sets it on the
     * queue payload. Carried through because it is the only evidence that
     * distinguishes a progress update ("Please wait while I search the
     * inventory for you.") from a real answer - and a progress update must
     * not clear the Operator Inbox Unanswered badge. Dropping it here is
     * what previously made the two indistinguishable downstream.
     */
    protected ?string $kind = null;

    protected function initialize(...$arguments): void
    {
        [$this->payload] = $arguments;

        $this->queue = 'ChatbotV3OutboundJob';
    }

    public function process(): void
    {
        $messageId = $this->payload['message_id'] ?? null;
        $this->chatbotResponse = $this->payload['chatbot_response'] ?? [];
        $this->kind = isset($this->payload['kind']) && $this->payload['kind'] !== ''
            ? (string) $this->payload['kind']
            : null;

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
                'kind' => $this->kind,
                'messages_count' => count($this->chatbotResponse['messages'] ?? []),
                'actions_count' => count($this->chatbotResponse['actions'] ?? []),
                'tool_payloads_count' => count($this->chatbotResponse['tool_payloads'] ?? []),
            ]));

            ProcessChatbotResponseJob::dispatch(
                $this->inboundMessage,
                $this->chatbotResponse,
                $this->integrationId,
                $this->kind
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
            return app(ChatbotIntegrationResolverService::class)->resolveIdFromMessage($this->inboundMessage);
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
