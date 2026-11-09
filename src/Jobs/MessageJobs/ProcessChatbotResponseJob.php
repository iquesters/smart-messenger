<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\MediaStorageService;

class ProcessChatbotResponseJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $chatbotResponse;
    protected ?int $integrationId;
    protected ?string $kind = null;

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->chatbotResponse, $this->integrationId] = $arguments;
        // Old three-argument jobs already in the queue remain valid. Without
        // a kind, text must not acquire definitive final-answer metadata.
        $this->kind = in_array($arguments[3] ?? null, ['progress', 'final'], true)
            ? $arguments[3] : null;
    }

    private function aiPayload(array $payload, bool $handoverAcknowledgement = false): array
    {
        $payload['integration_id'] = $this->integrationId;
        $payload['_inbox_reply_to_message_id'] = (string) $this->inboundMessage->id;
        if ($this->kind !== null) {
            // Deliberately not `_actor`: that key arms a separate pre-send
            // takeover guard. This release changes persisted classification
            // metadata only, never the WhatsApp sending decision.
            $payload['_inbox_actor'] = 'ai';
            $payload['_ai_kind'] = $this->kind;
        }
        if ($handoverAcknowledgement) {
            $payload['_handover_trigger'] = '1';
        }
        return $payload;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'session_id'         => $this->chatbotResponse['session_id'] ?? null,
        ]));

        $messages     = $this->chatbotResponse['messages'] ?? [];
        $actions      = $this->chatbotResponse['actions'] ?? [];
        $toolPayloads = $this->chatbotResponse['tool_payloads'] ?? [];

        // The queued reply can run immediately. Persist the Core action's
        // reason on *this* inbound before dispatching any outbound job.
        $handoverAckPending = $this->persistTriggeringHandoverMetadata($actions)
            && $this->kind !== 'progress';

        $this->logInfo('Chatbot response received' . $this->ctx([
            'inbound_message_id'   => $this->inboundMessage->id,
            'messages_count'       => count($messages),
            'actions_count'        => count($actions),
            'tool_payloads_count'  => is_array($toolPayloads) ? count($toolPayloads) : 0,
            'provider'             => $this->getProvider(),
        ]));

        $replyJobs = [];

        if (empty($messages)) {
            $this->logInfo('Chatbot response has no messages' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
        } else {
            foreach ($messages as $message) {
                // Core builds the action from its first text reply. Mark
                // exactly that reply, not products or unrelated later text.
                $isAcknowledgement = $handoverAckPending
                    && ($message['type'] ?? null) === 'text';
                $replyJob = $this->routeMessage($message, $isAcknowledgement);

                if ($replyJob) {
                    $replyJobs[] = $replyJob;
                    if ($isAcknowledgement) {
                        $handoverAckPending = false;
                    }
                }
            }
        }

        foreach ($this->extractToolPayloadReplyJobs($toolPayloads) as $replyJob) {
            $replyJobs[] = $replyJob;
        }

        if (!empty($replyJobs)) {
            Bus::chain($replyJobs)->dispatch();

            $this->logInfo('Dispatched chatbot reply chain' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'jobs_count'         => count($replyJobs),
                'provider'           => $this->getProvider(),
            ]));
        }

        if (!empty($actions)) {
            $this->processActions($actions);
        } else {
            $this->logInfo('No chatbot actions found for handover' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
        }

        $this->logMethodEnd($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
        ]));
    }

    /**
     * Get provider small_name from channel
     * Defaults to 'whatsapp' to avoid breaking existing flow
     */
    private function getProvider(): string
    {
        return $this->inboundMessage->channel->provider->small_name ?? 'whatsapp';
    }

    /**
     * Route chatbot message to correct reply job based on provider
     */
    private function routeMessage(array $message, bool $handoverAcknowledgement = false): mixed
    {
        $messageType = $message['type'] ?? 'unknown';

        $this->logDebug('Routing chatbot message' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'message_type'       => $messageType,
            'provider'           => $this->getProvider(),
        ]));

        return match ($messageType) {
            'product'  => $this->handleProduct($message),
            'text'     => $this->handleText($message, $handoverAcknowledgement),
            'image'    => $this->handleImage($message),
            'document' => $this->handleDocument($message),
            default    => $this->handleUnknown($message),
        };
    }

    /**
     * Handle text message - dispatch correct reply job based on provider
     */
    private function handleText(array $message, bool $handoverAcknowledgement = false): mixed
    {
        $text = $message['content']['text'] ?? null;

        if (!$text) {
            $this->logWarning('Text message content is empty from chatbot' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $payload = $this->aiPayload([
            'type' => 'text',
            'text' => $text,
        ], $handoverAcknowledgement);

        return match ($this->getProvider()) {
            'telegram' => new SendTelegramReplyJob($this->inboundMessage, $payload),
            default    => new SendWhatsAppReplyJob($this->inboundMessage, $payload),
        };
    }

    /**
     * Handle product message - dispatch correct reply job based on provider
     */
    private function handleProduct(array $message): mixed
    {
        $content = $message['content'] ?? [];

        if (empty($content)) {
            $this->logWarning('Product message has empty content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $imageUrl = $content['image_url'] ?? null;
        $caption  = $this->buildCaption($content);

        $storedMedia = null;
        if ($imageUrl) {
            $mediaService = new MediaStorageService($this->inboundMessage->channel);
            $storedMedia  = $mediaService->downloadFromUrlAndStore(
                $imageUrl,
                'image',
                ['filename' => 'product_image']
            );

            $this->logInfo('Product image downloaded for response' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'has_stored_media'   => !empty($storedMedia),
            ]));
        }

        // For Telegram - send the stored product image when available, otherwise fall back to text
        if ($this->getProvider() === 'telegram') {
            if ($storedMedia) {
                return new SendTelegramReplyJob(
                    $this->inboundMessage,
                    $this->aiPayload([
                        'type'           => 'image',
                        'image_url'      => $storedMedia['url'],
                        'caption'        => $caption,
                        'stored_media'   => $storedMedia,
                    ])
                );
            }

            $text = $this->buildProductText($content);
            if (!$text) {
                return null;
            }

            return new SendTelegramReplyJob(
                $this->inboundMessage,
                $this->aiPayload([
                    'type'           => 'text',
                    'text'           => $text,
                ])
            );
        }

        if ($imageUrl) {
            return new SendWhatsAppReplyJob(
                $this->inboundMessage,
                $this->aiPayload([
                    'type'            => 'image',
                    'image_url'       => $imageUrl,
                    'caption'         => $caption,
                    'stored_media'    => $storedMedia,
                    'product_content' => $content,
                ])
            );
        }

        $text = $this->buildProductText($content);
        if ($text) {
            return new SendWhatsAppReplyJob(
                $this->inboundMessage,
                $this->aiPayload([
                    'type'           => 'text',
                    'text'           => $text,
                ])
            );
        }

        return null;
    }

    private function handleUnknown(array $message): mixed
    {
        $this->logWarning('Unknown chatbot message type' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'message_type'       => $message['type'] ?? 'unknown',
        ]));

        return null;
    }

    /**
     * Handle document message (e.g. PDF report) - dispatch WhatsApp reply job
     */
    private function handleDocument(array $message): mixed
    {
        $content = $message['content'] ?? [];

        if (empty($content)) {
            $this->logWarning('Document message has empty content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $base64 = $content['base64'] ?? null;
        if (!$base64) {
            $this->logWarning('Document message has no base64 content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $mimeType = $content['mime_type'] ?? 'application/pdf';
        $filename = $content['filename'] ?? 'report.pdf';
        $caption  = $content['caption'] ?? null;

        $mediaService = new MediaStorageService($this->inboundMessage->channel);
        $storedMedia  = $mediaService->storeBase64Content(
            $base64,
            'document',
            $mimeType,
            ['filename' => $filename]
        );

        if (!$storedMedia) {
            $this->logWarning('Failed to persist document from chatbot message' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $this->logInfo('Document persisted and will be sent as outbound media' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'mime_type'          => $mimeType,
            'stored_url'         => $storedMedia['url'] ?? null,
        ]));

        return new SendWhatsAppReplyJob(
            $this->inboundMessage,
            $this->aiPayload([
                'type'           => 'document',
                'caption'        => $caption,
                'filename'       => $filename,
                'stored_media'   => $storedMedia,
            ])
        );
    }

    private function buildCaption(array $product): string
    {
        return implode(' - ', array_filter([
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

    private function handleImage(array $message): mixed
    {
        $content = $message['content'] ?? [];
        if (empty($content)) {
            $this->logWarning('Image message has empty content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $base64 = $content['base64'] ?? null;
        if (!$base64) {
            $this->logWarning('Image message has no base64 content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $mimeType = $content['mime_type'] ?? 'image/png';
        $mediaService = new MediaStorageService($this->inboundMessage->channel);
        $storedMedia = $mediaService->storeBase64Content(
            $base64,
            'image',
            $mimeType,
            [
                'filename' => 'analytics_chart',
            ]
        );

        if (!$storedMedia) {
            $this->logWarning('Failed to persist analytics chart image from image message' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $provider = $this->getProvider();

        $this->logInfo('Analytics chart image persisted and will be sent as outbound media' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'provider'           => $provider,
            'mime_type'          => $mimeType,
            'stored_url'         => $storedMedia['url'] ?? null,
        ]));

        if ($provider === 'telegram') {
            return new SendTelegramReplyJob(
                $this->inboundMessage,
                $this->aiPayload([
                    'type'           => 'image',
                    'caption'        => $content['caption'] ?? 'Analytics chart',
                    'image_url'      => $storedMedia['url'],
                    'stored_media'   => $storedMedia,
                ])
            );
        }

        return new SendWhatsAppReplyJob(
            $this->inboundMessage,
            $this->aiPayload([
                'type'           => 'image',
                'caption'        => 'Analytics chart',
                'image_url'      => $storedMedia['url'],
                'stored_media'   => $storedMedia,
            ])
        );
    }

    /**
     * Convert rich tool payload outputs, such as analytics charts returned as base64,
     * into outbound WhatsApp media replies.
     */
    private function extractToolPayloadReplyJobs(array $toolPayloads): array
    {
        if (empty($toolPayloads)) {
            return [];
        }

        $replyJobs = [];

        foreach ($toolPayloads as $toolName => $toolPayload) {
            if (!is_array($toolPayload)) {
                continue;
            }

            $chart  = $toolPayload['chart'] ?? null;
            $base64 = is_array($chart) ? ($chart['base64'] ?? null) : null;

            if (!$base64) {
                continue;
            }

            $mimeType = $chart['mime_type'] ?? 'image/png';
            $caption  = $toolPayload['summary'] ?? ($toolName . ' chart');

            $mediaService = new MediaStorageService($this->inboundMessage->channel);
            $storedMedia  = $mediaService->storeBase64Content(
                $base64,
                'image',
                $mimeType,
                ['filename' => strtolower((string) $toolName) . '_chart']
            );

            if (!$storedMedia) {
                $this->logWarning('Failed to persist chart image from tool payload' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'tool_name'          => $toolName,
                ]));
                continue;
            }

            // For Telegram - send chart image with caption, similar to WhatsApp
            if ($this->getProvider() === 'telegram') {
                $replyJobs[] = new SendTelegramReplyJob(
                    $this->inboundMessage,
                    $this->aiPayload([
                        'type'           => 'image',
                        'caption'        => $caption,
                        'image_url'      => $storedMedia['url'],
                        'stored_media'   => $storedMedia,
                    ])
                );
                continue;
            }

            $replyJobs[] = new SendWhatsAppReplyJob(
                $this->inboundMessage,
                $this->aiPayload([
                    'type'           => 'image',
                    'caption'        => $caption,
                    'image_url'      => $storedMedia['url'],
                    'stored_media'   => $storedMedia,
                ])
            );
        }

        return $replyJobs;
    }

    /** Persist source evidence before the queued acknowledgement can run. */
    private function persistTriggeringHandoverMetadata(array $actions): bool
    {
        foreach ($actions as $action) {
            $actionId = $action['id'] ?? null;
            $data = $action['data'] ?? [];
            if (!is_string($actionId) || $actionId === '' || !is_array($data)) {
                continue;
            }
            // Validate the existing queue mapping; a different action must
            // not make an ordinary answer look like a handover acknowledgement.
            if (DB::table('queues')->where('uid', $actionId)->value('name') !== 'ForwardToAgentJob') {
                continue;
            }
            $reason = null;
            foreach ($data as $item) {
                if (is_array($item) && ($item['key'] ?? null) === 'handover_reason'
                    && is_string($item['value'] ?? null)) {
                    $reason = trim($item['value']);
                    break;
                }
            }
            $this->persistHandoverMeta($actionId, $this->extractSummaryFromActionData($data), $reason);
            $this->logInfo('Triggering handover metadata persisted before reply dispatch' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
                'has_reason' => $reason !== null && $reason !== '',
            ]));
            return true;
        }
        return false;
    }

    /** @todo currently this treats chatbot action as agent handover trigger. */
    private function processActions(array $actions): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'actions_count'      => count($actions),
        ]));

        foreach ($actions as $action) {
            $actionId   = $action['id'] ?? null;
            $actionData = $action['data'] ?? [];

            if (!$actionId) {
                $this->logWarning('Skipping chatbot action without id' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                continue;
            }

            $this->logInfo('Resolving chatbot action to queue' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id'          => $actionId,
            ]));

            $queue = DB::table('queues')->where('uid', $actionId)->first();

            if (!$queue) {
                $this->logWarning('No queue found for chatbot action id' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id'          => $actionId,
                ]));
                continue;
            }

            $queueName = $queue->name ?? null;
            if (!$queueName) {
                $this->logWarning('Queue found without name for chatbot action' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id'          => $actionId,
                    'queue_id'           => $queue->id ?? null,
                ]));
                continue;
            }

            $jobClass = 'Iquesters\\SmartMessenger\\Jobs\\MessageJobs\\' . $queueName;

            $this->logInfo('Action queue resolved' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id'          => $actionId,
                'queue_name'         => $queueName,
                'queue_status'       => $queue->status ?? null,
                'job_class'          => $jobClass,
            ]));

            if ($jobClass !== ForwardToAgentJob::class) {
                $this->logWarning('Unsupported handover action job' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id'          => $actionId,
                    'job_class'          => $jobClass,
                ]));
                continue;
            }

            if (!class_exists($jobClass)) {
                $this->logError('Resolved handover job class does not exist' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id'          => $actionId,
                    'job_class'          => $jobClass,
                ]));
                continue;
            }

            if (!is_subclass_of($jobClass, BaseJob::class)) {
                $this->logError('Resolved handover job is not a BaseJob' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id'          => $actionId,
                    'job_class'          => $jobClass,
                ]));
                continue;
            }

            $summary     = $this->extractSummaryFromActionData($actionData);
            $contact     = $this->resolveContactForInboundMessage();
            $rawPayload  = is_array($this->inboundMessage->raw_payload) ? $this->inboundMessage->raw_payload : [];

            $handoverContext = [
                'source'     => 'chatbot_action',
                'action_id'  => $actionId,
                'queue_name' => $queueName,
                'summary'    => $summary,
            ];

            $this->logInfo('Dispatching handover job from chatbot action' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id'          => $actionId,
                'queue_name'         => $queueName,
                'job_class'          => $jobClass,
                'contact_id'         => $contact?->id,
                'has_summary'        => !empty($summary),
                'turns_count'        => count($summary['turns'] ?? []),
            ]));

            $jobClass::dispatch(
                $this->inboundMessage->fresh(),
                $rawPayload,
                $contact,
                $handoverContext
            );
        }

        $this->logMethodEnd($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'actions_count'      => count($actions),
        ]));
    }

    private function extractSummaryFromActionData(array $actionData): array
    {
        foreach ($actionData as $item) {
            if (($item['key'] ?? null) !== 'summary') {
                continue;
            }

            $value = $item['value'] ?? [];

            if (!is_array($value)) {
                $this->logWarning('Chatbot action summary is not an array' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'value_type'         => gettype($value),
                ]));
                return [];
            }

            $this->logInfo('Summary payload extracted from chatbot action' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'summary_version'    => $value['version'] ?? null,
                'turns_count'        => count($value['turns'] ?? []),
            ]));

            return $value;
        }

        $this->logWarning('No summary key found in chatbot action data' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'data_items'         => count($actionData),
        ]));

        return [];
    }

    private function resolveContactForInboundMessage(): ?Contact
    {
        $channel = $this->inboundMessage->channel;

        if (!$channel) {
            $this->logWarning('Cannot resolve contact: inbound message has no channel' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $organisationIds = $channel->organisations()->pluck('organisations.id');
        if ($organisationIds->isEmpty()) {
            $this->logWarning('Cannot resolve contact: channel has no organisations' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'channel_id'         => $channel->id,
            ]));
            return null;
        }

        $contact = Contact::query()
            ->where('identifier', $this->inboundMessage->from)
            ->whereHas('organisations', function ($query) use ($organisationIds) {
                $query->whereIn('organisations.id', $organisationIds);
            })
            ->first();

        $this->logInfo('Contact resolution for handover completed' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'channel_id'         => $channel->id,
            'contact_id'         => $contact?->id,
        ]));

        return $contact;
    }

    private function persistHandoverMeta(string $actionId, array $summary, ?string $reason): void
    {
        try {
            $this->inboundMessage->setMeta('chatbot_handover_action_id', $actionId);
            if (!empty($summary)) {
                $this->inboundMessage->setMeta('chatbot_handover_summary', json_encode($summary));
            }
            if ($reason !== null && $reason !== '') {
                $this->inboundMessage->setMeta('human_handover_reason', $reason);
            }

            $this->logInfo('Persisted handover metadata on inbound message' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id'          => $actionId,
                'turns_count'        => count($summary['turns'] ?? []),
                'has_reason'         => $reason !== null && $reason !== '',
            ]));
        } catch (\Throwable $e) {
            $this->logError('Failed to persist handover metadata' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id'          => $actionId,
                'error'              => $e->getMessage(),
            ]));
            throw $e;
        }
    }
}
