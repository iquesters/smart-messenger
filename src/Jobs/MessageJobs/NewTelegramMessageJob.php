<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Services\ContactService;

class NewTelegramMessageJob extends BaseJob
{
    protected Channel $channel;
    protected array   $message;
    protected array   $rawPayload;

    protected function initialize(...$arguments): void
    {
        [
            $this->channel,
            $this->message,
            $this->rawPayload,
        ] = $arguments;
    }

    /**
     * Main orchestrator
     */
    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'channel_id' => $this->channel->id,
            'message_id' => $this->message['message_id'] ?? 'unknown',
        ]));

        try {
            /**
             * Step 1 — Save incoming message
             */
            $savedMessage = $this->saveMessage();

            if (!$savedMessage) {
                $this->logWarning('Telegram message could not be saved, stopping processing' . $this->ctx([
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->message['message_id'] ?? 'unknown',
                ]));
                return;
            }

            /**
             * Step 2 — Handle contact
             */
            $contact = $this->handleContact($savedMessage);

            /**
             * Step 3 — Forward to chatbot
             */
            ForwardToChatbotJob::dispatch(
                $savedMessage,
                $this->rawPayload,
                $contact
            );

            $this->logInfo('ForwardToChatbotJob dispatched for Telegram message' . $this->ctx([
                'channel_id'       => $this->channel->id,
                'saved_message_id' => $savedMessage->id,
            ]));

        } catch (\Throwable $e) {
            $this->logError('NewTelegramMessageJob failed' . $this->ctx([
                'error'      => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['message_id'] ?? 'unknown',
            ]));
            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['message_id'] ?? 'unknown',
            ]));
        }
    }

    /**
     * Save the incoming Telegram message to DB
     */
    private function saveMessage(): ?Message
    {
        $telegramMessageId = $this->message['message_id'] ?? null;

        if (!$telegramMessageId) {
            $this->logWarning('Telegram message missing message_id');
            return null;
        }

        $uniqueId = 'tg_' . $telegramMessageId;

        // Prevent duplicates
        if (Message::where('message_id', $uniqueId)->exists()) {
            $this->logInfo('Duplicate Telegram message ignored' . $this->ctx([
                'message_id' => $uniqueId,
            ]));
            return null;
        }

        $fromId  = data_get($this->message, 'from.id');
        $chatId  = data_get($this->message, 'chat.id');
        $type    = $this->detectMessageType();
        $content = $this->extractMessageContent();

        $savedMessage = Message::create([
            'channel_id'   => $this->channel->id,
            'message_id'   => $uniqueId,
            'from'         => (string) $fromId,
            'to'           => (string) $chatId,
            'message_type' => $type,
            'content'      => $content,
            'timestamp'    => isset($this->message['date'])
                                ? now()->setTimestamp($this->message['date'])
                                : now(),
            'status'       => Constants::RECEIVED,
            'raw_payload'  => $this->rawPayload,
        ]);

        $this->logInfo('Telegram message saved' . $this->ctx([
            'saved_message_id' => $savedMessage->id,
            'type'             => $type,
            'from'             => $fromId,
        ]));

        return $savedMessage;
    }

    /**
     * Create or update contact from incoming message
     */
    private function handleContact(Message $savedMessage): mixed
    {
        $fromId     = data_get($this->message, 'from.id');
        $senderName = trim(
            (data_get($this->message, 'from.first_name') ?? '') . ' ' .
            (data_get($this->message, 'from.last_name') ?? '')
        ) ?: data_get($this->message, 'from.username');

        $identifier = (string) $fromId;

        if (!$identifier) {
            return null;
        }

        try {
            $contactService = app(ContactService::class);

            $contact = $contactService->createOrUpdateFromWebhook(
                $identifier,
                $senderName,
                $this->channel
            );

            $this->logInfo('Telegram contact handled' . $this->ctx([
                'contact_id' => $contact->id,
                'identifier' => $identifier,
                'channel_id' => $this->channel->id,
            ]));

            return $contact;

        } catch (\Throwable $e) {
            $this->logError('Failed to handle Telegram contact' . $this->ctx([
                'identifier' => $identifier,
                'error'      => $e->getMessage(),
            ]));
            return null;
        }
    }

    /**
     * Detect message type from Telegram payload
     */
    private function detectMessageType(): string
    {
        if (isset($this->message['text']))     return 'text';
        if (isset($this->message['photo']))    return 'image';
        if (isset($this->message['video']))    return 'video';
        if (isset($this->message['audio']))    return 'audio';
        if (isset($this->message['voice']))    return 'voice';
        if (isset($this->message['document'])) return 'document';
        if (isset($this->message['sticker']))  return 'sticker';
        if (isset($this->message['location'])) return 'location';
        if (isset($this->message['contact']))  return 'contact';

        return 'unknown';
    }

    /**
     * Extract message content based on type
     */
    private function extractMessageContent(): string
    {
        $type = $this->detectMessageType();

        return match ($type) {
            'text' => $this->message['text'] ?? '',

            'image' => json_encode([
                'caption' => $this->message['caption'] ?? '',
                'file_id' => data_get(
                    $this->message,
                    'photo.' . (count($this->message['photo']) - 1) . '.file_id',
                    ''
                ),
            ]),

            'video' => json_encode([
                'caption'   => $this->message['caption'] ?? '',
                'file_id'   => $this->message['video']['file_id'] ?? '',
                'duration'  => $this->message['video']['duration'] ?? '',
                'mime_type' => $this->message['video']['mime_type'] ?? '',
            ]),

            'audio' => json_encode([
                'file_id'   => $this->message['audio']['file_id'] ?? '',
                'duration'  => $this->message['audio']['duration'] ?? '',
                'mime_type' => $this->message['audio']['mime_type'] ?? '',
                'title'     => $this->message['audio']['title'] ?? '',
            ]),

            'voice' => json_encode([
                'file_id'   => $this->message['voice']['file_id'] ?? '',
                'duration'  => $this->message['voice']['duration'] ?? '',
                'mime_type' => $this->message['voice']['mime_type'] ?? '',
            ]),

            'document' => json_encode([
                'file_id'   => $this->message['document']['file_id'] ?? '',
                'file_name' => $this->message['document']['file_name'] ?? '',
                'mime_type' => $this->message['document']['mime_type'] ?? '',
                'caption'   => $this->message['caption'] ?? '',
            ]),

            'sticker' => json_encode([
                'file_id'  => $this->message['sticker']['file_id'] ?? '',
                'emoji'    => $this->message['sticker']['emoji'] ?? '',
                'animated' => $this->message['sticker']['is_animated'] ?? false,
            ]),

            'location' => json_encode([
                'latitude'  => $this->message['location']['latitude'] ?? '',
                'longitude' => $this->message['location']['longitude'] ?? '',
            ]),

            'contact' => json_encode([
                'phone_number' => $this->message['contact']['phone_number'] ?? '',
                'first_name'   => $this->message['contact']['first_name'] ?? '',
                'last_name'    => $this->message['contact']['last_name'] ?? '',
            ]),

            default => json_encode(['type' => $type, 'raw' => $this->message]),
        };
    }
}