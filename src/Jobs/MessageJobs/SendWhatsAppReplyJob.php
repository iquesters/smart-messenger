<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Services\AgentResolverService;

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
            $error = 'Missing channel or recipient for WhatsApp reply';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'has_channel' => !empty($channel),
                'to' => $to,
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
        $token = $channel->getMeta('system_user_token');

        if ((!$phoneNumberId || !$token) && $channel) {
            $resolvedChannel = app(AgentResolverService::class)
                ->resolveWhatsAppChannelForAgentRouting($channel);

            if ($resolvedChannel->id !== $channel->id) {
                $channel = $resolvedChannel;
                $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
                $token = $channel->getMeta('system_user_token');
            }
        }

        if (!$phoneNumberId || !$token) {
            $error = 'Missing WhatsApp channel credentials';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'has_phone_number_id' => !empty($phoneNumberId),
                'has_token' => !empty($token),
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        $requestPayload = $this->buildWhatsAppPayload($to);
        if (!$requestPayload) {
            $error = 'WhatsApp request payload could not be built';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'payload_type' => $this->payload['type'] ?? null,
                'payload_keys' => array_keys($this->payload),
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        $response = Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            $requestPayload
        );

        if (!$response->successful()) {
            $error = 'WhatsApp API send failed';
            $errorDetail = $response->json();
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'http_status' => $response->status(),
                'response' => $errorDetail,
            ];
            $this->logError($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context, $errorDetail);
            return;
        }

        $waMessageId = $response->json('messages.0.id');
        if (!$waMessageId) {
            $error = 'WhatsApp send succeeded but no message_id returned';
            $errorDetail = $response->json();
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $errorDetail,
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context, $errorDetail);
            return;
        }

        $messageType = $this->payload['type'];
        
        $this->logInfo('Saving message with integration' . $this->ctx([
            'integration_id' => $this->payload['integration_id']
                ?? $this->inboundMessage->integration_id
        ]));
        
        $outbound = Message::create([
            'channel_id'   => $channel->id,
            'integration_id' => $this->payload['integration_id']
                        ?? $this->inboundMessage->integration_id,
            'message_id'   => $waMessageId,
            'from'         => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to'           => $to,
            'message_type' => $messageType,
            'content'      => $this->buildContentForDatabase($messageType),
            'timestamp'    => now(),
            'status'       => Constants::SENT,
            'raw_payload'  => $response->json(),
            'created_by'   => $this->payload['created_by_override']
                  ?? $this->inboundMessage->created_by,
        ]);
        
        /**
         * Optional linkage if message is forwarded
        */
        if (!empty($this->payload['_forwarded_from'])) {
            $outbound->setMeta('forwarded_from', $this->payload['_forwarded_from']);
        }

        /**
         * 🔥 STORE MEDIA META (NEW SYSTEM)
         */
        if (!empty($this->payload['stored_media'])) {
            $media = $this->payload['stored_media'];

            $outbound->setMeta('media_driver', $media['driver']);
            $outbound->setMeta('media_path', $media['path']);
            $outbound->setMeta('media_url', $media['url']);
            $outbound->setMeta('mime_type', $media['mime_type']);
            $outbound->setMeta('media_size', (string) $media['size']);
        }
    }

    /**
     * Save a FAILED message so the UI shows the error instead of silently dropping it.
     */
    private function saveFailedMessage(
        ?Channel $channel,
        ?string $to,
        string $error,
        array $context = [],
        ?array $errorDetail = null,
    ): void {
        if (!$channel || !$to) {
            return;
        }

        $messageType = $this->payload['type'] ?? 'unknown';

        Message::create([
            'channel_id'    => $channel->id,
            'integration_id' => $this->payload['integration_id']
                        ?? $this->inboundMessage->integration_id,
            'from'          => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to'            => $to,
            'message_type'  => $messageType,
            'content'       => $this->buildContentForDatabase($messageType),
            'timestamp'     => now(),
            'status'        => Constants::FAILED,
            'raw_payload'   => [
                'error' => $error,
                'context' => $context,
                'error_detail' => $errorDetail,
                'failed_at' => now()->toIso8601String(),
            ],
            'created_by'    => $this->payload['created_by_override']
                  ?? $this->inboundMessage->created_by,
        ]);

        $this->logInfo('Saved FAILED message to UI' . $this->ctx([
            'error' => $error,
        ]));
    }

    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return $this->payload['text'] ?? '';
        }

        if ($type === 'image') {
            return json_encode([
                'caption'   => $this->payload['caption'] ?? '',
                'image_url' => $this->payload['image_url']
                    ?? ($this->payload['stored_media']['url'] ?? null),
            ]);
        }

        if ($type === 'video') {
            return json_encode([
                'caption' => $this->payload['caption'] ?? '',
                'video_url' => $this->payload['stored_media']['url'] ?? null,
            ]);
        }

        return json_encode($this->payload);
    }

    private function buildWhatsAppPayload(string $to): ?array
    {
        if ($this->payload['type'] === 'text') {
            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $this->payload['text']]
            ];
        }

        if ($this->payload['type'] === 'image') {

            if (empty($this->payload['stored_media'])) {
                $this->logError('Image payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp(
                $this->payload['stored_media']
            );

            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'image',
                'image'=> [
                    'id'      => $mediaId,
                    'caption' => $this->payload['caption'] ?? '',
                ]
            ];
        }

        if ($this->payload['type'] === 'video') {

            if (empty($this->payload['stored_media'])) {
                $this->logError('Video payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp(
                $this->payload['stored_media']
            );

            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'video',
                'video'=> [
                    'id'      => $mediaId,
                    'caption' => $this->payload['caption'] ?? '',
                ]
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
            $this->logError('Local media file missing' . $this->ctx([
                'path' => $absolutePath
            ]));
            return null;
        }

        $allowedMimes = [
            'image' => ['image/jpeg', 'image/png'],
            'video' => ['video/mp4', 'video/3gp'],
        ];
        $type = $this->payload['type'] ?? null;
        if ($type && isset($allowedMimes[$type]) && !in_array($mimeType, $allowedMimes[$type], true)) {
            $this->logError("Unsupported WhatsApp {$type} mime type after storage" . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'mime_type' => $mimeType,
                'path' => $storedMedia['path'] ?? null,
            ]));
            return null;
        }

        $response = Http::withToken(
            $channel->getMeta('system_user_token')
        )->attach(
            'file',
            fopen($absolutePath, 'r'),
            basename($absolutePath)
        )->post(
            "https://graph.facebook.com/v18.0/" .
            $channel->getMeta('whatsapp_phone_number_id') .
            "/media",
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
