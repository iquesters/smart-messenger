<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\Foundation\Models\ApiLog;

class TelegramWHController extends Controller
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
            'endpoint_provider' => 'telegram',
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
             * 1️⃣ INCOMING UPDATE (POST)
             * ---------------------------------------------
             */
            $payload = $request->all();

            // Telegram sends updates in different formats
            $message = $payload['message'] ?? null;
            $editedMessage = $payload['edited_message'] ?? null;
            $channelPost = $payload['channel_post'] ?? null;
            $callbackQuery = $payload['callback_query'] ?? null;

            // Handle different update types
            $incomingMessage = $message ?? $editedMessage ?? $channelPost;

            if (!$incomingMessage && !$callbackQuery) {
                $this->finalizeApiLog($webhookLog, 'ignored', 'No processable message');
                Log::info('Telegram webhook with no processable message');
                return response()->json(['ok' => true], 200);
            }

            // Extract basic info
            $updateId = $payload['update_id'] ?? null;
            $webhookLog->setMeta('update_id', $updateId);

            /**
             * ---------------------------------------------
             * 2️⃣ RESOLVE CHANNEL
             * ---------------------------------------------
             */
            $channel = Channel::where('uid', $channelUid)
                ->where('status', Constants::ACTIVE)
                ->with(['metas', 'provider'])
                ->first();

            if (!$channel) {
                $this->finalizeApiLog($webhookLog, 'failed', 'Channel not found');
                Log::warning('Channel not found or inactive', ['channel_uid' => $channelUid]);
                return response()->json(['ok' => false], 403);
            }

            $webhookLog->attachRef('channel', $channel->id);

            // Verify bot token matches (optional security check)
            $botToken = $channel->getMeta('telegram_bot_token');
            if (!$botToken) {
                $this->finalizeApiLog($webhookLog, 'failed', 'Bot token not configured');
                Log::warning('Bot token not configured', ['channel_uid' => $channelUid]);
                return response()->json(['ok' => false], 403);
            }

            Log::info('Channel validated successfully', [
                'channel_uid' => $channelUid,
                'channel_id' => $channel->id,
            ]);

            /**
             * ---------------------------------------------
             * 3️⃣ PROCESS & SAVE INCOMING MESSAGE
             * ---------------------------------------------
             */
            $savedMessage = null;

            if ($incomingMessage) {
                $savedMessage = $this->processMessage($channel, $incomingMessage, $payload, $webhookLog);
            } elseif ($callbackQuery) {
                $savedMessage = $this->processCallbackQuery($channel, $callbackQuery, $payload, $webhookLog);
            }

            if ($savedMessage) {
                $webhookLog->attachRef('message', $savedMessage->id);
            }

            $this->finalizeApiLog($webhookLog, 'success', 'Webhook processed successfully');
            return response()->json(['ok' => true], 200);

        } catch (\Throwable $e) {
            $this->finalizeApiLog($webhookLog, 'failed', $e->getMessage());
            
            Log::error('Telegram Webhook Fatal Error', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'channel_uid' => $channelUid ?? 'unknown'
            ]);

            return response()->json(['ok' => false], 500);
        }
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
        ApiLog $webhookLog
    ): ?Message {
        $messageId = $message['message_id'] ?? null;

        if (!$messageId) {
            Log::warning('Message without ID', ['message' => $message]);
            return null;
        }

        // Prevent duplicates (use combination of chat_id and message_id)
        $chatId = $message['chat']['id'] ?? null;
        $uniqueMessageId = $chatId . '_' . $messageId;

        if (Message::where('message_id', $uniqueMessageId)->exists()) {
            $webhookLog->setMeta('duplicate_message_id', $uniqueMessageId);
            Log::info('Duplicate message ignored', ['message_id' => $uniqueMessageId]);
            return null;
        }

        // Extract user info
        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;

        $contactName = null;
        $identifier = null;

        if ($from) {
            $identifier = (string)($from['id'] ?? null);
            $contactName = trim(
                ($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')
            );
            if (isset($from['username'])) {
                $contactName .= ' (@' . $from['username'] . ')';
            }
        }

        // Prepare message data
        $messageData = [
            'channel_id'   => $channel->id,
            'message_id'   => $uniqueMessageId,
            'from'         => $identifier,
            'to'           => $channel->getMeta('telegram_bot_username'),
            'message_type' => $this->detectMessageType($message),
            'content'      => $this->extractMessageContent($message),
            'timestamp'    => isset($message['date']) 
                                ? now()->setTimestamp($message['date']) 
                                : now(),
            'status'       => Constants::RECEIVED,
            'raw_payload'  => $rawPayload,
        ];

        if ($contactName) {
            $messageData['raw_payload']['contact_name'] = $contactName;
        }

        $savedMessage = Message::create($messageData);

        $webhookLog->setMetas([
            'message_type' => $messageData['message_type'],
            'message_from' => $identifier,
            'contact_name' => $contactName,
            'chat_type' => $chat['type'] ?? 'unknown',
        ]);

        Log::info('Telegram message saved successfully', [
            'id' => $savedMessage->id,
            'message_id' => $uniqueMessageId,
            'type' => $messageData['message_type'],
            'from' => $identifier
        ]);
        
        // Contact handling
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
     * Process Callback Query (Button Press)
     * ---------------------------------------------
     */
    private function processCallbackQuery(
        Channel $channel,
        array $callbackQuery,
        array $rawPayload,
        ApiLog $webhookLog
    ): ?Message {
        $callbackId = $callbackQuery['id'] ?? null;

        if (!$callbackId) {
            Log::warning('Callback query without ID', ['callback' => $callbackQuery]);
            return null;
        }

        // Extract user and message info
        $from = $callbackQuery['from'] ?? null;
        $message = $callbackQuery['message'] ?? null;

        $identifier = null;
        $contactName = null;

        if ($from) {
            $identifier = (string)($from['id'] ?? null);
            $contactName = trim(
                ($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')
            );
            if (isset($from['username'])) {
                $contactName .= ' (@' . $from['username'] . ')';
            }
        }

        $uniqueMessageId = 'callback_' . $callbackId;

        // Prepare callback data
        $messageData = [
            'channel_id'   => $channel->id,
            'message_id'   => $uniqueMessageId,
            'from'         => $identifier,
            'to'           => $channel->getMeta('telegram_bot_username'),
            'message_type' => 'callback_query',
            'content'      => json_encode([
                'data' => $callbackQuery['data'] ?? '',
                'message_id' => $message['message_id'] ?? null,
                'chat_id' => $message['chat']['id'] ?? null,
            ]),
            'timestamp'    => now(),
            'status'       => Constants::RECEIVED,
            'raw_payload'  => $rawPayload,
        ];

        if ($contactName) {
            $messageData['raw_payload']['contact_name'] = $contactName;
        }

        $savedMessage = Message::create($messageData);

        $webhookLog->setMetas([
            'message_type' => 'callback_query',
            'message_from' => $identifier,
            'contact_name' => $contactName,
            'callback_data' => $callbackQuery['data'] ?? '',
        ]);

        Log::info('Telegram callback query saved successfully', [
            'id' => $savedMessage->id,
            'callback_id' => $callbackId,
            'from' => $identifier
        ]);

        return $savedMessage;
    }

    /**
     * ---------------------------------------------
     * Detect Message Type
     * ---------------------------------------------
     */
    private function detectMessageType(array $message): string
    {
        if (isset($message['text'])) return 'text';
        if (isset($message['photo'])) return 'photo';
        if (isset($message['video'])) return 'video';
        if (isset($message['audio'])) return 'audio';
        if (isset($message['voice'])) return 'voice';
        if (isset($message['document'])) return 'document';
        if (isset($message['sticker'])) return 'sticker';
        if (isset($message['location'])) return 'location';
        if (isset($message['contact'])) return 'contact';
        if (isset($message['poll'])) return 'poll';
        if (isset($message['venue'])) return 'venue';
        if (isset($message['animation'])) return 'animation';
        if (isset($message['video_note'])) return 'video_note';
        
        return 'unknown';
    }

    /**
     * ---------------------------------------------
     * Extract Message Content Based on Type
     * ---------------------------------------------
     */
    private function extractMessageContent(array $message): string
    {
        // Text message
        if (isset($message['text'])) {
            return $message['text'];
        }

        // Photo
        if (isset($message['photo'])) {
            $photos = $message['photo'];
            $largestPhoto = end($photos); // Get largest resolution
            return json_encode([
                'caption' => $message['caption'] ?? '',
                'file_id' => $largestPhoto['file_id'] ?? '',
                'file_unique_id' => $largestPhoto['file_unique_id'] ?? '',
                'width' => $largestPhoto['width'] ?? 0,
                'height' => $largestPhoto['height'] ?? 0,
            ]);
        }

        // Video
        if (isset($message['video'])) {
            return json_encode([
                'caption' => $message['caption'] ?? '',
                'file_id' => $message['video']['file_id'] ?? '',
                'file_unique_id' => $message['video']['file_unique_id'] ?? '',
                'duration' => $message['video']['duration'] ?? 0,
                'width' => $message['video']['width'] ?? 0,
                'height' => $message['video']['height'] ?? 0,
                'mime_type' => $message['video']['mime_type'] ?? '',
            ]);
        }

        // Audio
        if (isset($message['audio'])) {
            return json_encode([
                'caption' => $message['caption'] ?? '',
                'file_id' => $message['audio']['file_id'] ?? '',
                'file_unique_id' => $message['audio']['file_unique_id'] ?? '',
                'duration' => $message['audio']['duration'] ?? 0,
                'performer' => $message['audio']['performer'] ?? '',
                'title' => $message['audio']['title'] ?? '',
                'mime_type' => $message['audio']['mime_type'] ?? '',
            ]);
        }

        // Voice
        if (isset($message['voice'])) {
            return json_encode([
                'file_id' => $message['voice']['file_id'] ?? '',
                'file_unique_id' => $message['voice']['file_unique_id'] ?? '',
                'duration' => $message['voice']['duration'] ?? 0,
                'mime_type' => $message['voice']['mime_type'] ?? '',
            ]);
        }

        // Document
        if (isset($message['document'])) {
            return json_encode([
                'caption' => $message['caption'] ?? '',
                'file_id' => $message['document']['file_id'] ?? '',
                'file_unique_id' => $message['document']['file_unique_id'] ?? '',
                'file_name' => $message['document']['file_name'] ?? '',
                'mime_type' => $message['document']['mime_type'] ?? '',
            ]);
        }

        // Sticker
        if (isset($message['sticker'])) {
            return json_encode([
                'file_id' => $message['sticker']['file_id'] ?? '',
                'file_unique_id' => $message['sticker']['file_unique_id'] ?? '',
                'width' => $message['sticker']['width'] ?? 0,
                'height' => $message['sticker']['height'] ?? 0,
                'is_animated' => $message['sticker']['is_animated'] ?? false,
                'is_video' => $message['sticker']['is_video'] ?? false,
                'emoji' => $message['sticker']['emoji'] ?? '',
            ]);
        }

        // Location
        if (isset($message['location'])) {
            return json_encode([
                'latitude' => $message['location']['latitude'] ?? '',
                'longitude' => $message['location']['longitude'] ?? '',
                'horizontal_accuracy' => $message['location']['horizontal_accuracy'] ?? null,
            ]);
        }

        // Contact
        if (isset($message['contact'])) {
            return json_encode([
                'phone_number' => $message['contact']['phone_number'] ?? '',
                'first_name' => $message['contact']['first_name'] ?? '',
                'last_name' => $message['contact']['last_name'] ?? '',
                'user_id' => $message['contact']['user_id'] ?? null,
            ]);
        }

        // Poll
        if (isset($message['poll'])) {
            return json_encode([
                'question' => $message['poll']['question'] ?? '',
                'options' => $message['poll']['options'] ?? [],
                'is_anonymous' => $message['poll']['is_anonymous'] ?? true,
                'type' => $message['poll']['type'] ?? 'regular',
            ]);
        }

        // Venue
        if (isset($message['venue'])) {
            return json_encode([
                'location' => $message['venue']['location'] ?? [],
                'title' => $message['venue']['title'] ?? '',
                'address' => $message['venue']['address'] ?? '',
            ]);
        }

        // Animation (GIF)
        if (isset($message['animation'])) {
            return json_encode([
                'file_id' => $message['animation']['file_id'] ?? '',
                'file_unique_id' => $message['animation']['file_unique_id'] ?? '',
                'width' => $message['animation']['width'] ?? 0,
                'height' => $message['animation']['height'] ?? 0,
                'duration' => $message['animation']['duration'] ?? 0,
            ]);
        }

        // Video Note
        if (isset($message['video_note'])) {
            return json_encode([
                'file_id' => $message['video_note']['file_id'] ?? '',
                'file_unique_id' => $message['video_note']['file_unique_id'] ?? '',
                'length' => $message['video_note']['length'] ?? 0,
                'duration' => $message['video_note']['duration'] ?? 0,
            ]);
        }

        // Default: return raw message as JSON
        return json_encode([
            'type' => $this->detectMessageType($message),
            'raw' => $message
        ]);
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
}