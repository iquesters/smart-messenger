<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;

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
        $channel = $this->inboundMessage->channel;
        $to      = $this->inboundMessage->from;

        if (!$channel || !$to) {
            return;
        }

        $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
        $token         = $channel->getMeta('system_user_token');

        if (!$phoneNumberId || !$token) {
            return;
        }

        $requestPayload = $this->buildWhatsAppPayload($to);
        if (!$requestPayload) {
            return;
        }

        $response = Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            $requestPayload
        );

        if (!$response->successful()) {
            Log::error('WhatsApp send failed', [
                'response' => $response->json()
            ]);
            return;
        }

        $waMessageId = $response->json('messages.0.id');
        if (!$waMessageId) {
            return;
        }

        $messageType = $this->payload['type'];

        $outbound = Message::create([
            'channel_id'   => $channel->id,
            'message_id'   => $waMessageId,
            'from'         => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to'           => $to,
            'message_type' => $messageType,
            'content'      => $this->buildContentForDatabase($messageType),
            'timestamp'    => now(),
            'status'       => Constants::SENT,
            'raw_payload'  => $response->json(),
            'created_by'   => $this->inboundMessage->created_by,
        ]);

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

    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return $this->payload['text'] ?? '';
        }

        if ($type === 'image') {
            return json_encode([
                'caption'   => $this->payload['caption'] ?? '',
                'image_url' => $this->payload['image_url'] ?? null, // original URL
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
            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'image',
                'image'=> [
                    'link'    => $this->payload['image_url'], // 🔥 ORIGINAL
                    'caption' => $this->payload['caption'] ?? ''
                ]
            ];
        }

        return null;
    }
}