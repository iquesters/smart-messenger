<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Events\MessageSentEvent;

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
        try {
            Log::info('=== SendWhatsAppReplyJob STARTED ===', [
                'payload_type' => $this->payload['type'] ?? 'unknown',
                'payload' => $this->payload
            ]);

            $to = $this->inboundMessage->from;
            if (!$to) {
                Log::warning('SendWhatsAppReplyJob: No recipient (from field empty)');
                return;
            }

            $channel = $this->inboundMessage->channel;
            if (!$channel) {
                Log::warning('SendWhatsAppReplyJob: Channel not found', [
                    'channel_id' => $this->inboundMessage->channel_id
                ]);
                return;
            }

            $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
            $token = $channel->getMeta('system_user_token');

            if (!$phoneNumberId || !$token) {
                Log::warning('SendWhatsAppReplyJob: Missing WhatsApp credentials', [
                    'has_phone_number_id' => !empty($phoneNumberId),
                    'has_token' => !empty($token),
                    'channel_id' => $channel->id
                ]);
                return;
            }

            Log::info('WhatsApp credentials loaded', [
                'phone_number_id' => $phoneNumberId,
                'has_token' => !empty($token)
            ]);

            $requestPayload = $this->buildWhatsAppPayload($to);

            if (!$requestPayload) {
                Log::warning('SendWhatsAppReplyJob: buildWhatsAppPayload returned null', [
                    'payload' => $this->payload
                ]);
                return;
            }

            Log::info('Sending to WhatsApp API', [
                'url' => "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                'request_payload' => $requestPayload
            ]);

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                $requestPayload
            );

            Log::info('WhatsApp API response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response' => $response->json()
            ]);

            if (!$response->successful()) {
                Log::error('WhatsApp send failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'payload' => $requestPayload
                ]);
                return;
            }

            $waMessageId = $response->json('messages.0.id');

            if ($waMessageId) {
                $messageType = $this->payload['type'] ?? 'text';
                
                // Build content in Meta format
                $content = $this->buildContentForDatabase($messageType);

                Log::info('Creating outbound message record', [
                    'message_type' => $messageType,
                    'wa_message_id' => $waMessageId,
                    'content' => $content
                ]);

                $outbound = Message::create([
                    'channel_id' => $channel->id,
                    'message_id' => $waMessageId,
                    'from' => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
                    'to' => $to,
                    'message_type' => $messageType,
                    'content' => $content,
                    'timestamp' => now(),
                    'status' => Constants::SENT,
                    'raw_payload' => $response->json(),
                    'created_by' => $this->inboundMessage->created_by,
                ]);

                Log::info('Message created and broadcasting event', [
                    'message_id' => $outbound->id,
                    'type' => $messageType,
                    'content_preview' => substr($content, 0, 100)
                ]);

                broadcast(new MessageSentEvent($outbound));

                Log::info('=== SendWhatsAppReplyJob COMPLETED ===');
            }

        } catch (\Throwable $e) {
            Log::error('SendWhatsAppReplyJob error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $this->payload
            ]);
        }
    }

    /**
     * Build content for database storage in Meta-like format
     */
    private function buildContentForDatabase(string $messageType): string
    {
        if ($messageType === 'text') {
            // For text messages, just store the text directly
            return $this->payload['text'] ?? '';
        }

        if ($messageType === 'image') {
            // For images, store in Meta format like incoming messages
            $imageContent = [
                'caption' => $this->payload['caption'] ?? '',
            ];

            // Add image URL for reference (optional but useful)
            if (!empty($this->payload['image_url'])) {
                $imageContent['image_url'] = $this->payload['image_url'];
            }

            return json_encode($imageContent);
        }

        // Fallback for other types
        return json_encode($this->payload);
    }

    /**
     * Build WhatsApp API payload (TEXT and IMAGE support)
     */
    private function buildWhatsAppPayload(string $to): ?array
    {
        $type = $this->payload['type'] ?? 'text';

        Log::info('buildWhatsAppPayload called', [
            'type' => $type,
            'to' => $to
        ]);

        // TEXT MESSAGE
        if ($type === 'text') {
            $text = $this->payload['text'] ?? '';
            
            // Ensure text is not empty
            if (empty($text)) {
                Log::warning('buildWhatsAppPayload: text is empty', [
                    'payload' => $this->payload
                ]);
                return null;
            }

            Log::info('Building TEXT payload', [
                'text_length' => strlen($text)
            ]);

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $text
                ]
            ];
        }

        // IMAGE MESSAGE
        if ($type === 'image') {
            $imageUrl = $this->payload['image_url'] ?? null;
            
            if (empty($imageUrl)) {
                Log::warning('buildWhatsAppPayload: image_url is empty', [
                    'payload' => $this->payload
                ]);
                return null;
            }

            Log::info('Building IMAGE payload', [
                'image_url' => $imageUrl,
                'caption' => $this->payload['caption'] ?? ''
            ]);

            $imagePayload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'image',
                'image' => [
                    'link' => $imageUrl,
                ]
            ];

            // Only add caption if it's not empty
            $caption = $this->payload['caption'] ?? '';
            if (!empty($caption)) {
                $imagePayload['image']['caption'] = $caption;
            }

            Log::info('IMAGE payload built', [
                'payload' => $imagePayload
            ]);

            return $imagePayload;
        }

        Log::warning('buildWhatsAppPayload: unsupported message type', [
            'type' => $type,
            'payload' => $this->payload
        ]);

        return null;
    }
}