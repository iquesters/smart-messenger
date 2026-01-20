<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Message;

class SendWhatsAppReplyJob extends BaseJob
{
    protected Message $inboundMessage;
    protected string $replyText;
    
    protected function initialize(...$arguments): void
    {
        [$inboundMessage, $replyText] = $arguments;

        $this->inboundMessage = $inboundMessage;
        $this->replyText = $replyText;
    }
    
    /**
     * Handle the job - Send WhatsApp reply
     */
    public function process(): void
    {
        try {
            $waUserNumber = $this->inboundMessage->from;

            if (!$waUserNumber) {
                Log::warning('No sender number for reply', [
                    'message_id' => $this->inboundMessage->id
                ]);
                return;
            }

            // Idempotency check
            $cacheKey = "wa_reply_sent:{$waUserNumber}:{$this->inboundMessage->message_id}";
            if (cache()->has($cacheKey)) {
                Log::info('Duplicate reply prevented by cache', [
                    'message_id' => $this->inboundMessage->id,
                    'to' => $waUserNumber
                ]);
                return;
            }

            // Get channel details
            $channel = $this->inboundMessage->channel;

            if (!$channel) {
                Log::error('Channel not found for message', [
                    'message_id' => $this->inboundMessage->id
                ]);
                return;
            }

            $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
            $token = $channel->getMeta('system_user_token');

            if (!$phoneNumberId || !$token) {
                Log::error('Missing WhatsApp credentials', [
                    'channel_id' => $channel->id,
                    'message_id' => $this->inboundMessage->id
                ]);
                return;
            }

            Log::info('Sending WhatsApp reply', [
                'message_id' => $this->inboundMessage->id,
                'to' => $waUserNumber,
                'reply_length' => strlen($this->replyText)
            ]);

            // Send message via WhatsApp API
            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $waUserNumber,
                    'type' => 'text',
                    'text' => ['body' => $this->replyText]
                ]
            );

            if (!$response->successful()) {
                Log::error('WhatsApp send failed', [
                    'message_id' => $this->inboundMessage->id,
                    'to' => $waUserNumber,
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return;
            }

            $waMessageId = $response->json('messages.0.id') ?? null;

            Log::info('WhatsApp reply sent successfully', [
                'message_id' => $this->inboundMessage->id,
                'wa_message_id' => $waMessageId,
                'to' => $waUserNumber
            ]);

            // Set cache to prevent duplicates (2 minutes)
            cache()->put($cacheKey, true, now()->addMinutes(2));

            // Save outbound message to database
            if ($waMessageId) {
                Message::create([
                    'channel_id' => $channel->id,
                    'message_id' => $waMessageId,
                    'from' => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
                    'to' => $waUserNumber,
                    'message_type' => 'text',
                    'content' => $this->replyText,
                    'timestamp' => now(),
                    'status' => Constants::SENT,
                    'raw_payload' => $response->json(),
                ]);

                Log::info('Outbound message saved to database', [
                    'wa_message_id' => $waMessageId,
                    'to' => $waUserNumber
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('SendWhatsAppReplyJob failed', [
                'message_id' => $this->inboundMessage->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}