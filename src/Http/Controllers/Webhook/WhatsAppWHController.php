<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\Foundation\Models\ApiLog;

class WhatsAppWHController extends Controller
{
    protected $contactService;

    public function __construct(ContactService $contactService)
    {
        $this->contactService = $contactService;
    }

    public function handle(Request $request, string $channelUid)
    {
        // 📊 Start logging the webhook request
        $webhookLog = $this->createApiLog([
            'ref_type' => 'webhook',
            'ref_id' => $channelUid,
            'endpoint_provider' => 'whatsapp',
            'event' => 'webhook_received',
            'direction' => 'inbound',
            'endpoint' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'status' => 'processing',
        ]);

        // Store request payload
        $webhookLog->setMetas([
            'http_method' => $request->method(),
            'headers' => json_encode($request->headers->all()),
            'payload' => json_encode($request->all()),
            'query_params' => json_encode($request->query()),
        ]);

        try {
            /**
             * ---------------------------------------------
             * 1️⃣ WEBHOOK VERIFICATION (GET)
             * ---------------------------------------------
             */
            if ($request->isMethod('get') && $request->input('hub_mode') === 'subscribe') {
                $verifyToken = $request->input('hub_verify_token');
                $challenge   = $request->input('hub_challenge');

                $webhookLog->setMeta('verification_attempt', true);
                $webhookLog->setMeta('hub_mode', $request->input('hub_mode'));

                $channel = Channel::where('uid', $channelUid)
                    ->where('status', Constants::ACTIVE)
                    ->with(['metas', 'provider'])
                    ->first();

                if (!$channel) {
                    $this->finalizeApiLog($webhookLog, 'failed', 'Channel not found');
                    Log::warning('Channel not found or inactive', ['channel_uid' => $channelUid]);
                    return response('Invalid channel', 403);
                }

                $webhookLog->attachRef('channel', $channel->id);

                $meta = $channel->metas()
                    ->where('meta_key', 'webhook_verify_token')
                    ->where('meta_value', $verifyToken)
                    ->first();

                if (!$meta) {
                    $this->finalizeApiLog($webhookLog, 'failed', 'Invalid verification token');
                    Log::warning('Invalid verification token for channel', ['channel_uid' => $channelUid]);
                    return response('Invalid verification token', 403);
                }

                $this->finalizeApiLog($webhookLog, 'success', 'Webhook verified');
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            /**
             * ---------------------------------------------
             * 2️⃣ INCOMING MESSAGE (POST)
             * ---------------------------------------------
             */
            $payload = $request->all();

            $this->handleStatusUpdates($payload, $webhookLog);

            $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

            if (!$phoneNumberId) {
                $this->finalizeApiLog($webhookLog, 'ignored', 'Status-only webhook');
                Log::info('Status-only webhook received');
                return response()->json(['status' => Constants::OK], 200);
            }

            $waUserNumber = data_get($payload, 'entry.0.changes.0.value.messages.0.from');

            if (!$waUserNumber) {
                $this->finalizeApiLog($webhookLog, 'ignored', 'No sender number');
                Log::info('No sender number (status update)');
                return response()->json(['status' => Constants::OK], 200);
            }

            $webhookLog->setMetas([
                'phone_number_id' => $phoneNumberId,
                'sender_number' => $waUserNumber,
            ]);

            /**
             * ---------------------------------------------
             * 3️⃣ RESOLVE CHANNEL WITH EXTRA VALIDATION
             * ---------------------------------------------
             */
            $channel = Channel::where('uid', $channelUid)
                ->where('status', Constants::ACTIVE)
                ->with(['metas', 'provider'])
                ->first();

            if (!$channel) {
                $this->finalizeApiLog($webhookLog, 'failed', 'Channel not found');
                Log::warning('Channel not found or inactive', ['channel_uid' => $channelUid]);
                return response()->json(['status' => Constants::IGNORED], 403);
            }

            $webhookLog->attachRef('channel', $channel->id);

            $phoneNumberIdMeta = $channel->getMeta('whatsapp_phone_number_id');

            if ($phoneNumberIdMeta !== $phoneNumberId) {
                $this->finalizeApiLog($webhookLog, 'failed', 'Phone number ID mismatch');
                Log::warning('Phone number ID mismatch', [
                    'channel_uid' => $channelUid,
                    'expected_phone_id' => $phoneNumberIdMeta,
                    'received_phone_id' => $phoneNumberId
                ]);
                return response()->json(['status' => Constants::IGNORED], 403);
            }

            Log::info('Channel validated successfully', [
                'channel_uid' => $channelUid,
                'channel_id' => $channel->id,
                'phone_number_id' => $phoneNumberId
            ]);

            /**
             * ---------------------------------------------
             * 4️⃣ PROCESS & SAVE INCOMING MESSAGE
             * ---------------------------------------------
             */
            $messages = data_get($payload, 'entry.0.changes.0.value.messages', []);
            $metadata = data_get($payload, 'entry.0.changes.0.value.metadata');
            $contacts = data_get($payload, 'entry.0.changes.0.value.contacts', []);

            $savedMessage = null;
            foreach ($messages as $message) {
                $savedMessage = $this->processMessage($channel, $message, $payload, $metadata, $contacts, $webhookLog);
            }

            if ($savedMessage) {
                $webhookLog->attachRef('message', $savedMessage->id);
            }

            /**
             * ---------------------------------------------
             * 5️⃣ FORWARD TO EXTERNAL BOT API
             * ---------------------------------------------
             */
            ignore_user_abort(true);
            set_time_limit(0);

            Log::info('Calling NAMS API', [
                'url' => 'https://api.nams.site/webhook/whatsapp/v1',
                'payload' => $payload
            ]);

            // 📊 Log chatbot API call
            $chatbotCallLog = $this->createApiLog([
                'ref_type' => 'message',
                'ref_id' => $savedMessage?->id,
                'endpoint_provider' => 'nams',
                'event' => 'api_call',
                'direction' => 'outbound',
                'endpoint' => 'https://api.nams.site/webhook/whatsapp/v1',
                'status' => 'processing',
            ]);

            if ($savedMessage) {
                $chatbotCallLog->attachRef('channel', $channel->id);
                $chatbotCallLog->attachRef('webhook', $webhookLog->id);
            }

            $chatbotCallLog->setMeta('request_payload', json_encode($payload));

            $response = Http::post('https://api.nams.site/webhook/whatsapp/v1', $payload);

            $chatbotCallLog->setMetas([
                'response_status' => $response->status(),
                'response_body' => json_encode($response->json()),
            ]);

            Log::info('NAMS API response received', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            if (!$response->successful()) {
                $this->finalizeApiLog($chatbotCallLog, 'failed', 'API call failed');
                $this->finalizeApiLog($webhookLog, 'success', 'Webhook processed (bot API failed)');
                
                Log::error('External API POST failed', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return response()->json(['status' => Constants::OK], 200);
            }

            $messageId = $response->json('message_id');

            if (!$messageId) {
                $this->finalizeApiLog($chatbotCallLog, 'failed', 'No message_id returned');
                $this->finalizeApiLog($webhookLog, 'success', 'Webhook processed (no message_id)');
                
                Log::error('No message_id returned', ['response' => $response->json()]);
                return response()->json(['status' => Constants::OK], 200);
            }

            $chatbotCallLog->setMeta('message_id', $messageId);
            $this->finalizeApiLog($chatbotCallLog, 'success', 'Chatbot API call successful');

            /**
             * ---------------------------------------------
             * 6️⃣ POLL BOT RESPONSE (MAX 20s)
             * ---------------------------------------------
             */
            $pollLog = $this->createApiLog([
                'ref_type' => 'message',
                'ref_id' => $savedMessage?->id,
                'endpoint_provider' => 'nams',
                'event' => 'poll',
                'direction' => 'outbound',
                'endpoint' => "https://api.nams.site/messages/{$messageId}/response",
                'status' => 'processing',
            ]);

            if ($savedMessage) {
                $pollLog->attachRef('channel', $channel->id);
                $pollLog->attachRef('webhook', $webhookLog->id);
                $pollLog->attachRef('chatbot_call', $chatbotCallLog->id);
            }

            $pollLog->setMeta('message_id', $messageId);

            $start = microtime(true);
            $pollCount = 0;

            while ((microtime(true) - $start) < 20) {
                $pollCount++;
                
                $poll = Http::get("https://api.nams.site/messages/{$messageId}/response");
                $status = $poll->status();
                $body   = $poll->json();

                // 🔁 Still processing
                if ($status === 202 || ($status === 200 && empty($body['ready']))) {
                    sleep(1);
                    continue;
                }

                // ❌ Terminal errors
                if (in_array($status, [400, 404, 409])) {
                    $pollLog->setMetas([
                        'poll_count' => $pollCount,
                        'final_status' => $status,
                        'final_response' => json_encode($body),
                    ]);
                    $this->finalizeApiLog($pollLog, 'failed', "Terminal error: {$status}");
                    
                    Log::error('Polling terminal error', [
                        'status' => $status,
                        'response' => $body
                    ]);
                    break;
                }

                // ✅ Completed
                if ($status === 200 && ($body['ready'] ?? false) === true) {
                    $pollLog->setMetas([
                        'poll_count' => $pollCount,
                        'final_status' => $status,
                        'final_response' => json_encode($body),
                    ]);

                    if (!empty($body['failed'])) {
                        $this->finalizeApiLog($pollLog, 'failed', 'Bot processing failed');
                        
                        Log::error('Bot processing failed', [
                            'error' => $body['inbound']['error_message'] ?? null
                        ]);
                        break;
                    }

                    $replyText = data_get($body, 'outbound.content.messages.0.text');

                    if (!$replyText) {
                        $this->finalizeApiLog($pollLog, 'failed', 'Reply text missing');
                        Log::warning('Reply text missing');
                        break;
                    }

                    $this->finalizeApiLog($pollLog, 'success', 'Bot response received');

                    // 🔒 Idempotency (by sender)
                    $cacheKey = "wa_reply_sent:{$waUserNumber}";
                    if (cache()->has($cacheKey)) {
                        $webhookLog->setMeta('duplicate_reply_prevented', true);
                        break;
                    }

                    cache()->put($cacheKey, true, now()->addMinutes(2));

                    // 📤 Send WhatsApp reply with logging
                    $this->sendWhatsAppText(
                        $phoneNumberId,
                        $waUserNumber,
                        $replyText,
                        $channel->getMeta('system_user_token'),
                        $channel,
                        $savedMessage,
                        $webhookLog
                    );

                    Log::info('WhatsApp reply sent', ['to' => $waUserNumber]);
                    break;
                }

                sleep(1);
            }

            // If polling timed out
            if ((microtime(true) - $start) >= 20) {
                $pollLog->setMeta('poll_count', $pollCount);
                $this->finalizeApiLog($pollLog, 'failed', 'Polling timeout');
            }

            $this->finalizeApiLog($webhookLog, 'success', 'Webhook fully processed');
            return response()->json(['status' => Constants::OK], 200);

        } catch (\Throwable $e) {
            $this->finalizeApiLog($webhookLog, 'failed', $e->getMessage());
            
            Log::error('WhatsApp Webhook Fatal Error', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'channel_uid' => $channelUid ?? 'unknown'
            ]);

            return response()->json(['status' => Constants::ERROR], 500);
        }
    }

    /**
     * ---------------------------------------------
     * Send WhatsApp Text with API Logging
     * ---------------------------------------------
     */
    private function sendWhatsAppText(
        string $phoneNumberId,
        string $to,
        string $text,
        string $token,
        ?Channel $channel = null,
        ?Message $relatedMessage = null,
        ?ApiLog $webhookLog = null
    ): void {
        // 📊 Create send message API log
        $sendLog = $this->createApiLog([
            'ref_type' => 'message',
            'ref_id' => $relatedMessage?->id,
            'endpoint_provider' => 'whatsapp',
            'event' => 'send_message',
            'direction' => 'outbound',
            'endpoint' => "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            'status' => 'processing',
        ]);

        if ($channel) {
            $sendLog->attachRef('channel', $channel->id);
        }
        if ($webhookLog) {
            $sendLog->attachRef('webhook', $webhookLog->id);
        }

        $sendLog->setMetas([
            'phone_number_id' => $phoneNumberId,
            'recipient' => $to,
            'message_text' => $text,
        ]);

        try {
            Log::info('Sending WhatsApp message', [
                'phone_number_id' => $phoneNumberId,
                'to' => $to,
                'text' => $text
            ]);

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $text]
                ]
            );

            $sendLog->setMetas([
                'response_status' => $response->status(),
                'response_body' => json_encode($response->json()),
            ]);

            Log::info('WhatsApp API response', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            if (!$response->successful()) {
                $this->finalizeApiLog($sendLog, 'failed', 'WhatsApp send failed');
                
                Log::error('WhatsApp send failed', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return;
            }

            $waMessageId = $response->json('messages.0.id') ?? null;
            $sendLog->setMeta('wa_message_id', $waMessageId);

            // Save the sent message to database
            if ($waMessageId && $channel) {
                $sentMessage = Message::create([
                    'channel_id' => $channel->id,
                    'message_id' => $waMessageId,
                    'from' => $channel->getMeta('whatsapp_phone_number_id'),
                    'to' => $to,
                    'message_type' => 'text',
                    'content' => $text,
                    'timestamp' => now(),
                    'status' => Constants::SENT,
                    'raw_payload' => $response->json(),
                ]);

                $sendLog->attachRef('sent_message', $sentMessage->id);

                Log::info('Outbound WhatsApp message saved', [
                    'message_id' => $waMessageId,
                    'to' => $to
                ]);
            }

            $this->finalizeApiLog($sendLog, 'success', 'Message sent successfully');

        } catch (\Throwable $e) {
            $sendLog->setMeta('exception', $e->getMessage());
            $this->finalizeApiLog($sendLog, 'failed', 'Exception: ' . $e->getMessage());
            
            Log::error('WhatsApp send exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * ---------------------------------------------
     * Handle Status Updates with Logging
     * ---------------------------------------------
     */
    private function handleStatusUpdates(array $payload, ApiLog $webhookLog): void
    {
        $statuses = data_get($payload, 'entry.0.changes.0.value.statuses', []);

        if (empty($statuses)) {
            return;
        }

        $webhookLog->setMeta('status_updates_count', count($statuses));
        $statusUpdates = [];

        foreach ($statuses as $status) {
            $messageId = $status['id'] ?? null;
            $newStatus = $status['status'] ?? null;

            if (!$messageId || !$newStatus) {
                continue;
            }

            $message = Message::where('message_id', $messageId)->first();

            if ($message) {
                $message->update([
                    'status' => $newStatus,
                    'raw_response' => $status
                ]);

                $statusUpdates[] = [
                    'message_id' => $messageId,
                    'status' => $newStatus,
                ];

                Log::info('Message status updated', [
                    'message_id' => $messageId,
                    'status' => $newStatus
                ]);
            }
        }

        $webhookLog->setMeta('status_updates', json_encode($statusUpdates));
    }

    /**
     * ---------------------------------------------
     * Process Single Message with Logging
     * ---------------------------------------------
     */
    private function processMessage(
        Channel $channel,
        array $message,
        array $rawPayload,
        ?array $metadata,
        array $contacts = [],
        ApiLog $webhookLog
    ): ?Message {
        $messageId = $message['id'] ?? null;

        if (!$messageId) {
            Log::warning('Message without ID', ['message' => $message]);
            return null;
        }

        // Prevent duplicates
        if (Message::where('message_id', $messageId)->exists()) {
            $webhookLog->setMeta('duplicate_message_id', $messageId);
            Log::info('Duplicate message ignored', ['message_id' => $messageId]);
            return null;
        }

        // Extract contact info
        $contactName = null;
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? null) === ($message['from'] ?? null)) {
                $contactName = $contact['profile']['name'] ?? null;
                break;
            }
        }

        // Prepare message data
        $messageData = [
            'channel_id'           => $channel->id,
            'message_id'           => $messageId,
            'from'                 => $message['from'] ?? null,
            'to'                   => $metadata['display_phone_number'] 
                                        ?? $metadata['phone_number_id'] 
                                        ?? null,
            'message_type'         => $message['type'] ?? 'unknown',
            'content'              => $this->extractMessageContent($message),
            'timestamp'            => isset($message['timestamp']) 
                                        ? now()->setTimestamp($message['timestamp']) 
                                        : now(),
            'status'               => Constants::RECEIVED,
            'raw_payload'          => $rawPayload,
        ];

        if ($contactName) {
            $messageData['raw_payload']['contact_name'] = $contactName;
        }

        $savedMessage = Message::create($messageData);

        $webhookLog->setMetas([
            'message_type' => $message['type'],
            'message_from' => $message['from'],
            'contact_name' => $contactName,
        ]);

        Log::info('Message saved successfully', [
            'id' => $savedMessage->id,
            'message_id' => $messageId,
            'type' => $message['type'],
            'from' => $message['from']
        ]);
        
        // Contact handling
        $identifier = $message['from'] ?? null;

        if ($identifier) {
            try {
                $contact = $this->contactService->createOrUpdateFromWebhook(
                    $identifier,
                    $contactName,
                    $channel
                );

                $webhookLog->attachRef('contact', $contact->id);

                Log::info('Contact handled from webhook via service', [
                    'contact_id' => $contact->id,
                    'identifier' => $identifier,
                    'channel_id' => $channel->id,
                ]);

            } catch (\Throwable $e) {
                Log::error('Failed to handle contact from webhook', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $savedMessage;
    }

    /**
     * ---------------------------------------------
     * API Log Helper Methods
     * ---------------------------------------------
     */
    private function createApiLog(array $data): ApiLog
    {
        $data['uid'] = Str::ulid()->toBase32();
        $data['start_ts'] = now();
        
        return ApiLog::create($data);
    }

    private function finalizeApiLog(ApiLog $log, string $status, ?string $note = null): void
    {
        $log->update([
            'end_ts' => now(),
            'status' => $status,
        ]);

        if ($note) {
            $log->setMeta('note', $note);
        }

        $duration = $log->durationMs();
        if ($duration !== null) {
            $log->setMeta('duration_ms', $duration);
        }
    }

    /**
     * ---------------------------------------------
     * Send WhatsApp Reply
     * ---------------------------------------------
     */
    private function sendReply(Channel $channel, array $message): void
    {
        try {
            $token = $channel->getMeta('system_user_token');
            $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');

            if (!$token || !$phoneNumberId) {
                Log::error('Missing WhatsApp credentials for reply', [
                    'channel_id' => $channel->id,
                    'has_token' => !empty($token),
                    'has_phone_id' => !empty($phoneNumberId)
                ]);
                return;
            }

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $message['from'],
                    'text' => [
                        'body' => 'Echo: ' . ($message['text']['body'] ?? '')
                    ],
                    'context' => [
                        'message_id' => $message['id']
                    ]
                ]
            );

            if ($response->successful()) {
                Log::info('Reply sent successfully', [
                    'to' => $message['from'],
                    'message_id' => $message['id']
                ]);
            } else {
                Log::error('Failed to send reply', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception sending reply', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ---------------------------------------------
     * Mark Message as Read
     * ---------------------------------------------
     */
    private function markMessageAsRead(Channel $channel, array $message): void
    {
        try {
            $token = $channel->getMeta('system_user_token');
            $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');

            if (!$token || !$phoneNumberId) {
                return;
            }

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'status' => Constants::READ,
                    'message_id' => $message['id']
                ]
            );

            if ($response->successful()) {
                Log::info('Message marked as read', [
                    'message_id' => $message['id']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception marking message as read', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ---------------------------------------------
     * Extract Message Content Based on Type
     * ---------------------------------------------
     */
    private function extractMessageContent(array $message): string
    {
        $type = $message['type'] ?? 'unknown';

        return match ($type) {
            'text' => $message['text']['body'] ?? '',
            
            'image' => json_encode([
                'caption' => $message['image']['caption'] ?? '',
                'mime_type' => $message['image']['mime_type'] ?? '',
                'sha256' => $message['image']['sha256'] ?? '',
                'id' => $message['image']['id'] ?? ''
            ]),
            
            'video' => json_encode([
                'caption' => $message['video']['caption'] ?? '',
                'mime_type' => $message['video']['mime_type'] ?? '',
                'sha256' => $message['video']['sha256'] ?? '',
                'id' => $message['video']['id'] ?? ''
            ]),
            
            'audio' => json_encode([
                'mime_type' => $message['audio']['mime_type'] ?? '',
                'sha256' => $message['audio']['sha256'] ?? '',
                'id' => $message['audio']['id'] ?? '',
                'voice' => $message['audio']['voice'] ?? false
            ]),
            
            'document' => json_encode([
                'filename' => $message['document']['filename'] ?? '',
                'caption' => $message['document']['caption'] ?? '',
                'mime_type' => $message['document']['mime_type'] ?? '',
                'sha256' => $message['document']['sha256'] ?? '',
                'id' => $message['document']['id'] ?? ''
            ]),
            
            'sticker' => json_encode([
                'mime_type' => $message['sticker']['mime_type'] ?? '',
                'sha256' => $message['sticker']['sha256'] ?? '',
                'id' => $message['sticker']['id'] ?? '',
                'animated' => $message['sticker']['animated'] ?? false
            ]),
            
            'location' => json_encode([
                'latitude' => $message['location']['latitude'] ?? '',
                'longitude' => $message['location']['longitude'] ?? '',
                'name' => $message['location']['name'] ?? '',
                'address' => $message['location']['address'] ?? ''
            ]),
            
            'contacts' => json_encode($message['contacts'] ?? []),
            
            'button' => json_encode([
                'text' => $message['button']['text'] ?? '',
                'payload' => $message['button']['payload'] ?? ''
            ]),
            
            'interactive' => json_encode([
                'type' => $message['interactive']['type'] ?? '',
                'button_reply' => $message['interactive']['button_reply'] ?? null,
                'list_reply' => $message['interactive']['list_reply'] ?? null
            ]),
            
            'reaction' => json_encode([
                'message_id' => $message['reaction']['message_id'] ?? '',
                'emoji' => $message['reaction']['emoji'] ?? ''
            ]),
            
            default => json_encode([
                'type' => $type,
                'raw' => $message
            ])
        };
    }
}