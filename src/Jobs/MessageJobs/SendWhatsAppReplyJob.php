<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Message;

class SendWhatsAppReplyJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $payload;

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->payload] = $arguments;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'payload_type' => $this->payload['type'] ?? null,
        ]));

        $channel = $this->inboundMessage->channel;
        $to = $this->payload['to_override'] ?? $this->inboundMessage->from;

        if (!$channel || !$to) {
            $this->logWarning('Missing channel or recipient for WhatsApp reply' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'has_channel' => !empty($channel),
                'to' => $to,
            ]));
            return;
        }

        if ($this->isTestMessage()) {
            $syntheticId = 'test-out-' . strtolower((string) Str::ulid());
            $this->saveOutboundMessage($syntheticId, [
                'success' => true,
                'test_mode' => true,
                'synthetic_message_id' => $syntheticId,
                'source_inbound_message_id' => $this->inboundMessage->id,
                'payload' => $this->payload,
            ]);
            return;
        }

        $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
        $token = $channel->getMeta('system_user_token');

        if (!$phoneNumberId || !$token) {
            $this->logWarning('Missing WhatsApp channel credentials' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'has_phone_number_id' => !empty($phoneNumberId),
                'has_token' => !empty($token),
            ]));
            return;
        }

        $requestPayload = $this->buildWhatsAppPayload($to);
        if (!$requestPayload) {
            $this->logWarning('WhatsApp request payload could not be built' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'payload_type' => $this->payload['type'] ?? null,
            ]));
            return;
        }

        $response = Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            $requestPayload
        );

        if (!$response->successful()) {
            $this->logError('WhatsApp send failed' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $response->json(),
            ]));
            return;
        }

        $waMessageId = $response->json('messages.0.id');
        if (!$waMessageId) {
            $this->logWarning('WhatsApp send succeeded without message id' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $response->json(),
            ]));
            return;
        }

        $this->saveOutboundMessage($waMessageId, $response->json());
    }

    protected function isTestMessage(): bool
    {
        return (string) $this->inboundMessage->getMeta('is_test') === '1';
    }

    protected function saveOutboundMessage(string $messageId, array $rawPayload): Message
    {
        $channel = $this->inboundMessage->channel;
        $to = $this->payload['to_override'] ?? $this->inboundMessage->from;
        $messageType = $this->payload['type'];

        $this->logInfo('Saving message with integration' . $this->ctx([
            'integration_id' => $this->payload['integration_id'] ?? $this->inboundMessage->integration_id,
            'message_id' => $messageId,
            'is_test' => $this->isTestMessage(),
        ]));

        $outbound = Message::create([
            'channel_id' => $channel->id,
            'integration_id' => $this->payload['integration_id'] ?? $this->inboundMessage->integration_id,
            'message_id' => $messageId,
            'from' => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to' => $to,
            'message_type' => $messageType,
            'content' => $this->buildContentForDatabase($messageType),
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => $rawPayload,
            'created_by' => $this->payload['created_by_override'] ?? $this->inboundMessage->created_by,
            'updated_by' => $this->payload['created_by_override'] ?? $this->inboundMessage->updated_by,
        ]);

        if (!empty($this->payload['_forwarded_from'])) {
            $outbound->setMeta('forwarded_from', $this->payload['_forwarded_from']);
        }

        if ($this->isTestMessage()) {
            $outbound->setMeta('is_test', '1');
            foreach (['chatbot_test_run_uid', 'chatbot_test_run_item_uid', 'chatbot_test_case_uid'] as $metaKey) {
                $metaValue = $this->inboundMessage->getMeta($metaKey);
                if ($metaValue !== null && $metaValue !== '') {
                    $outbound->setMeta($metaKey, (string) $metaValue);
                }
            }
        }

        if (!empty($this->payload['stored_media'])) {
            $media = $this->payload['stored_media'];
            $outbound->setMeta('media_driver', $media['driver']);
            $outbound->setMeta('media_path', $media['path']);
            $outbound->setMeta('media_url', $media['url']);
            $outbound->setMeta('mime_type', $media['mime_type']);
            $outbound->setMeta('media_size', (string) $media['size']);
        }

        return $outbound;
    }

    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return $this->payload['text'] ?? '';
        }

        if ($type === 'image') {
            return json_encode([
                'caption' => $this->payload['caption'] ?? '',
                'image_url' => $this->payload['image_url'] ?? ($this->payload['stored_media']['url'] ?? null),
            ]);
        }

        return json_encode($this->payload);
    }

    private function buildWhatsAppPayload(string $to): ?array
    {
        if ($this->payload['type'] === 'text') {
            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $this->payload['text']],
            ];
        }

        if ($this->payload['type'] === 'image') {
            if (empty($this->payload['stored_media'])) {
                $this->logError('Image payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp($this->payload['stored_media']);
            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'image',
                'image' => [
                    'id' => $mediaId,
                    'caption' => $this->payload['caption'] ?? '',
                ],
            ];
        }

        return null;
    }

    private function uploadLocalMediaToWhatsApp(array $storedMedia): ?string
    {
        $channel = $this->inboundMessage->channel;
        $mimeType = $storedMedia['mime_type'] ?? 'application/octet-stream';
        $absolutePath = storage_path('app/public/' . $storedMedia['path']);

        if (!file_exists($absolutePath)) {
            $this->logError('Local media file missing' . $this->ctx(['path' => $absolutePath]));
            return null;
        }

        if (($this->payload['type'] ?? null) === 'image' && !in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            $this->logError('Unsupported WhatsApp image mime type after storage' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'mime_type' => $mimeType,
                'path' => $storedMedia['path'] ?? null,
            ]));
            return null;
        }

        $response = Http::withToken($channel->getMeta('system_user_token'))
            ->attach('file', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post(
                'https://graph.facebook.com/v18.0/' . $channel->getMeta('whatsapp_phone_number_id') . '/media',
                [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]
            );

        if (!$response->successful()) {
            $this->logError('WhatsApp media upload failed' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $response->json(),
            ]));
            return null;
        }

        return $response->json('id');
    }
}