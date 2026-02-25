<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\MediaStorageService;

class ProcessChatbotResponseJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $chatbotResponse;
    protected ?int $integrationId;
    
    protected function initialize(...$arguments): void
    {
        $this->inboundMessage = $arguments[0];
        $this->chatbotResponse = is_array($arguments[1] ?? null) ? $arguments[1] : [];
        $this->integrationId = $arguments[2] ?? null;
    }

    public function process(): void
    {
        $handoverSummary = $this->extractHandoverSummaryV1();

        if ($handoverSummary) {
            $this->persistHandoverSummary($handoverSummary);
        }

        $messages = $this->chatbotResponse['messages'] ?? [];

        if (empty($messages)) {
            Log::info('Chatbot response has no messages', [
                'inbound_message_id' => $this->inboundMessage->id
            ]);
            return;
        }

        foreach ($messages as $message) {
            $this->routeMessage($message);
        }
    }

    private function routeMessage(array $message): void
    {
        $messageType = $message['type'] ?? 'unknown';
        
        match ($messageType) {
            'product' => $this->handleProduct($message),
            'text'    => $this->handleText($message),
            default   => $this->handleUnknown($message),
        };
        
        // 🔥 LONGER DELAY AFTER IMAGES (they need more processing time)
        if ($messageType === 'product' && isset($message['content']['image_url'])) {
            usleep(1000000); // 1 second for images
        } else {
            usleep(300000); // 300ms for text
        }
    }

    /**
     * ===========================
     * PRODUCT HANDLER
     * ===========================
     */
    private function handleProduct(array $message): void
    {
        $content = $message['content'] ?? [];
        if (empty($content)) {
            return;
        }

        $imageUrl = $content['image_url'] ?? null;
        $caption  = $this->buildWhatsAppCaption($content);

        /**
         * 🔥 STORE MEDIA LOCALLY (NEW SYSTEM)
         */
        $storedMedia = null;
        if ($imageUrl) {
            $mediaService = new MediaStorageService($this->inboundMessage->channel);

            $storedMedia = $mediaService->downloadFromUrlAndStore(
                $imageUrl,
                'image',
                ['filename' => 'product_image']
            );
        }
        /**
         * ✅ SEND USING ORIGINAL IMAGE URL (OLD WORKING WAY)
         * 🔥 CHANGED: dispatchSync → dispatch to prevent blocking
         */
        if ($imageUrl) {
            SendWhatsAppReplyJob::dispatchSync(  // ← KEEP dispatchSync for ordering
                $this->inboundMessage,
                [
                    'type'            => 'image',
                    'image_url'       => $imageUrl,
                    'caption'         => $caption,
                    'stored_media'    => $storedMedia,
                    'product_content' => $content,
                    'integration_id'  => $this->integrationId,
                ]
            );
            return;
        }

        /**
         * FALLBACK → TEXT
         */
        $text = $this->buildProductText($content);
        if ($text) {
            SendWhatsAppReplyJob::dispatchSync(
                $this->inboundMessage,
                [
                    'type' => 'text',
                    'text' => $text,
                    'integration_id' => $this->integrationId,
                ]
            );
        }
    }

    private function buildWhatsAppCaption(array $product): string
    {
        return implode(' • ', array_filter([
            $product['title'] ?? null,
            !empty($product['sku']) ? 'SKU: ' . $product['sku'] : null,
            isset($product['price']) ? 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '') : null,
            $product['link'] ?? null,
        ]));
    }

    private function buildProductText(array $product): string
    {
        return implode("\n", array_filter([
            $product['title'] ?? null,
            !empty($product['sku']) ? 'SKU: ' . $product['sku'] : null,
            isset($product['price']) ? 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '') : null,
            $product['link'] ?? null,
        ]));
    }

    private function handleText(array $message): void
    {
        $text = $message['content']['text'] ?? null;
        if (!$text) {
            return;
        }

        SendWhatsAppReplyJob::dispatchSync(
            $this->inboundMessage,
            [
                'type' => 'text',
                'text' => $text,
                'integration_id'  => $this->integrationId,
            ]
        );
    }

    private function handleUnknown(array $message): void
    {
        Log::warning('Unknown chatbot message type', [
            'payload' => $message
        ]);
    }

    private function extractHandoverSummaryV1(): ?array
    {
        $actions = $this->chatbotResponse['actions'] ?? [];

        if (!is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $actionId = isset($action['id']) ? (string) $action['id'] : null;
            $actionData = $action['data'] ?? [];

            if (!is_array($actionData)) {
                continue;
            }

            foreach ($actionData as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (($entry['key'] ?? null) !== 'summary') {
                    continue;
                }

                $value = $entry['value'] ?? null;

                if (!is_array($value) || ($value['version'] ?? null) !== 'v1') {
                    continue;
                }

                $normalized = $this->normalizeHandoverSummaryV1($value);

                if (!$normalized) {
                    continue;
                }

                return [
                    'action_id' => $actionId,
                    'session_id' => isset($this->chatbotResponse['session_id'])
                        ? (string) $this->chatbotResponse['session_id']
                        : null,
                    'summary' => $normalized,
                ];
            }
        }

        return null;
    }

    private function normalizeHandoverSummaryV1(array $summary): ?array
    {
        $turns = [];
        $rawTurns = $summary['turns'] ?? [];

        if (is_array($rawTurns)) {
            foreach ($rawTurns as $turn) {
                if (!is_array($turn)) {
                    continue;
                }

                $userMessage = trim((string) ($turn['user_message'] ?? ''));
                $chatbotAnswer = trim((string) ($turn['chatbot_answer'] ?? ''));

                if ($userMessage === '' && $chatbotAnswer === '') {
                    continue;
                }

                $turns[] = [
                    'user_message' => $userMessage,
                    'chatbot_answer' => $chatbotAnswer,
                ];
            }
        }

        $fullSummary = trim((string) ($summary['full_conversation_summary'] ?? ''));
        $triggerSummary = trim((string) ($summary['handover_trigger_summary'] ?? ''));
        $nextBestAction = trim((string) ($summary['agent_next_best_action'] ?? ''));

        if (empty($turns) && $fullSummary === '' && $triggerSummary === '' && $nextBestAction === '') {
            return null;
        }

        return [
            'version' => 'v1',
            'turns' => $turns,
            'full_conversation_summary' => $fullSummary,
            'handover_trigger_summary' => $triggerSummary,
            'agent_next_best_action' => $nextBestAction,
        ];
    }

    private function persistHandoverSummary(array $payload): void
    {
        try {
            $summaryJson = json_encode(
                $payload['summary'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($summaryJson === false) {
                Log::warning('Failed to encode handover summary JSON', [
                    'inbound_message_id' => $this->inboundMessage->id,
                ]);
                return;
            }

            $this->inboundMessage->setMeta('handover_summary_v1', $summaryJson);

            if (!empty($payload['session_id'])) {
                $this->inboundMessage->setMeta('handover_session_id', (string) $payload['session_id']);
            }

            if (!empty($payload['action_id'])) {
                $this->inboundMessage->setMeta('handover_action_id', (string) $payload['action_id']);
            }

            Log::info('Handover summary stored on inbound message', [
                'inbound_message_id' => $this->inboundMessage->id,
                'session_id' => $payload['session_id'] ?? null,
                'action_id' => $payload['action_id'] ?? null,
                'turn_count' => count($payload['summary']['turns'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to persist handover summary', [
                'inbound_message_id' => $this->inboundMessage->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
