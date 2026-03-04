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

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->chatbotResponse, $this->integrationId] = $arguments;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'session_id' => $this->chatbotResponse['session_id'] ?? null,
        ]));

        $messages = $this->chatbotResponse['messages'] ?? [];
        $actions = $this->chatbotResponse['actions'] ?? [];

        $this->logInfo('Chatbot response received' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'messages_count' => count($messages),
            'actions_count' => count($actions),
        ]));

        if (empty($messages)) {
            $this->logInfo('Chatbot response has no messages' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
        } else {
            $replyJobs = [];

            foreach ($messages as $message) {
                $replyJob = $this->routeMessage($message);

                if ($replyJob) {
                    $replyJobs[] = $replyJob;
                }
            }

            if (!empty($replyJobs)) {
                Bus::chain($replyJobs)->dispatch();

                $this->logInfo('Dispatched chatbot reply chain' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'jobs_count' => count($replyJobs),
                ]));
            }
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

    private function routeMessage(array $message): ?SendWhatsAppReplyJob
    {
        $messageType = $message['type'] ?? 'unknown';

        $this->logDebug('Routing chatbot message' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'message_type' => $messageType,
        ]));

        return match ($messageType) {
            'product' => $this->handleProduct($message),
            'text' => $this->handleText($message),
            default => $this->handleUnknown($message),
        };
    }

    private function handleProduct(array $message): ?SendWhatsAppReplyJob
    {
        $content = $message['content'] ?? [];
        if (empty($content)) {
            $this->logWarning('Product message has empty content' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        $imageUrl = $content['image_url'] ?? null;
        $caption = $this->buildWhatsAppCaption($content);

        $storedMedia = null;
        if ($imageUrl) {
            $mediaService = new MediaStorageService($this->inboundMessage->channel);
            $storedMedia = $mediaService->downloadFromUrlAndStore(
                $imageUrl,
                'image',
                ['filename' => 'product_image']
            );

            $this->logInfo('Product image downloaded for response' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'has_stored_media' => !empty($storedMedia),
            ]));
        }

        if ($imageUrl) {
            return new SendWhatsAppReplyJob(
                $this->inboundMessage,
                [
                    'type' => 'image',
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                    'stored_media' => $storedMedia,
                    'product_content' => $content,
                    'integration_id' => $this->integrationId,
                ]
            );
        }

        $text = $this->buildProductText($content);
        if ($text) {
            return new SendWhatsAppReplyJob(
                $this->inboundMessage,
                [
                    'type' => 'text',
                    'text' => $text,
                    'integration_id' => $this->integrationId,
                ]
            );
        }

        return null;
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

    private function handleText(array $message): ?SendWhatsAppReplyJob
    {
        $text = $message['content']['text'] ?? null;
        if (!$text) {
            $this->logWarning('Text message content is empty from chatbot' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return null;
        }

        return new SendWhatsAppReplyJob(
            $this->inboundMessage,
            [
                'type' => 'text',
                'text' => $text,
                'integration_id' => $this->integrationId,
            ]
        );
    }

    private function handleUnknown(array $message): ?SendWhatsAppReplyJob
    {
        $this->logWarning('Unknown chatbot message type' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'message_type' => $message['type'] ?? 'unknown',
        ]));

        return null;
    }

    /**
     * @todo currently this treats chatbot action as agent handover trigger.
     */
    private function processActions(array $actions): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'actions_count' => count($actions),
        ]));

        foreach ($actions as $action) {
            $actionId = $action['id'] ?? null;
            $actionData = $action['data'] ?? [];

            if (!$actionId) {
                $this->logWarning('Skipping chatbot action without id' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                continue;
            }

            $this->logInfo('Resolving chatbot action to queue' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
            ]));

            $queue = DB::table('queues')->where('uid', $actionId)->first();

            if (!$queue) {
                $this->logWarning('No queue found for chatbot action id' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id' => $actionId,
                ]));
                continue;
            }

            $queueName = $queue->name ?? null;
            if (!$queueName) {
                $this->logWarning('Queue found without name for chatbot action' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id' => $actionId,
                    'queue_id' => $queue->id ?? null,
                ]));
                continue;
            }

            $jobClass = 'Iquesters\\SmartMessenger\\Jobs\\MessageJobs\\' . $queueName;

            $this->logInfo('Action queue resolved' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
                'queue_name' => $queueName,
                'queue_status' => $queue->status ?? null,
                'job_class' => $jobClass,
            ]));

            if ($jobClass !== ForwardToAgentJob::class) {
                $this->logWarning('Unsupported handover action job' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id' => $actionId,
                    'job_class' => $jobClass,
                ]));
                continue;
            }

            if (!class_exists($jobClass)) {
                $this->logError('Resolved handover job class does not exist' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id' => $actionId,
                    'job_class' => $jobClass,
                ]));
                continue;
            }

            if (!is_subclass_of($jobClass, BaseJob::class)) {
                $this->logError('Resolved handover job is not a BaseJob' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                    'action_id' => $actionId,
                    'job_class' => $jobClass,
                ]));
                continue;
            }

            $summary = $this->extractSummaryFromActionData($actionData);
            $contact = $this->resolveContactForInboundMessage();
            $rawPayload = is_array($this->inboundMessage->raw_payload) ? $this->inboundMessage->raw_payload : [];

            if (!empty($summary)) {
                $this->persistHandoverMeta($actionId, $summary);
            }

            $handoverContext = [
                'source' => 'chatbot_action',
                'action_id' => $actionId,
                'queue_name' => $queueName,
                'summary' => $summary,
            ];

            $this->logInfo('Dispatching handover job from chatbot action' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
                'queue_name' => $queueName,
                'job_class' => $jobClass,
                'contact_id' => $contact?->id,
                'has_summary' => !empty($summary),
                'turns_count' => count($summary['turns'] ?? []),
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
            'actions_count' => count($actions),
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
                    'value_type' => gettype($value),
                ]));
                return [];
            }

            $this->logInfo('Summary payload extracted from chatbot action' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'summary_version' => $value['version'] ?? null,
                'turns_count' => count($value['turns'] ?? []),
            ]));

            return $value;
        }

        $this->logWarning('No summary key found in chatbot action data' . $this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'data_items' => count($actionData),
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
                'channel_id' => $channel->id,
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
            'channel_id' => $channel->id,
            'contact_id' => $contact?->id,
        ]));

        return $contact;
    }

    private function persistHandoverMeta(string $actionId, array $summary): void
    {
        try {
            $this->inboundMessage->setMeta('chatbot_handover_action_id', $actionId);
            $this->inboundMessage->setMeta('chatbot_handover_summary', json_encode($summary));

            $this->logInfo('Persisted handover metadata on inbound message' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
                'turns_count' => count($summary['turns'] ?? []),
            ]));
        } catch (\Throwable $e) {
            $this->logError('Failed to persist handover metadata' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'action_id' => $actionId,
                'error' => $e->getMessage(),
            ]));
        }
    }

}
