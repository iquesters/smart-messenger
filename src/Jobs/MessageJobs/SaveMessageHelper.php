<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\SmartMessenger\Services\MediaStorageService;

class SaveMessageHelper
{
    protected Channel $channel;
    protected array $message;
    protected array $rawPayload;
    protected ?array $metadata;
    protected array $contacts;
    protected string $platform;

    /**
     * Constructor
     */
    public function __construct(
        Channel $channel,
        array $message,
        array $rawPayload,
        ?array $metadata = null,
        array $contacts = []
    ) {
        $this->channel    = $channel;
        $this->message    = $message;
        $this->rawPayload = $rawPayload;
        $this->metadata   = $metadata;
        $this->contacts   = $contacts;
        $this->platform   = $this->detectPlatform();
    }

    /**
     * Detect platform based on message structure
     * Telegram messages have 'message_id', WhatsApp messages have 'id'
     */
    private function detectPlatform(): string
    {
        return isset($this->message['message_id']) ? 'telegram' : 'whatsapp';
    }

    /**
     * @return array{message: Message, contact: ?\Iquesters\SmartMessenger\Models\Contact, is_duplicate: bool}
     */
    public function process(): array
    {
        try {
            $messageId = $this->getMessageId();

            if (!$messageId) {
                Log::warning('Message without ID', ['message' => $this->message]);
                throw new \Exception('Message ID is required');
            }

            // Prevent duplicates
            $existingMessage = Message::where('message_id', $messageId)->first();
            if ($existingMessage) {
                Log::info('Duplicate message, returning existing', ['message_id' => $messageId]);
                return [
                    'message'      => $existingMessage,
                    'contact'      => null,
                    'is_duplicate' => true,
                ];
            }

            // Extract contact name
            $contactName = $this->getContactName();

            $messageType = $this->getMessageType();

            // Handle media download if applicable (WhatsApp only for now)
            $mediaData = null;
            if ($this->platform === 'whatsapp' && in_array($messageType, ['image', 'document', 'audio', 'video', 'sticker'])) {
                $mediaData = $this->handleMediaDownload($messageType);

                if (!$mediaData) {
                    Log::warning('Failed to download media, continuing without it', [
                        'message_id' => $messageId,
                        'type'       => $messageType,
                    ]);
                }
            }

            // Prepare message data
            $messageData = [
                'channel_id'   => $this->channel->id,
                'message_id'   => $messageId,
                'from'         => $this->getFrom(),
                'to'           => $this->getTo(),
                'message_type' => $messageType,
                'content'      => $this->extractMessageContent($this->message),
                'timestamp'    => $this->getTimestamp(),
                'status'       => Constants::RECEIVED,
                'raw_payload'  => $this->rawPayload,
            ];

            if ($contactName) {
                $messageData['raw_payload']['contact_name'] = $contactName;
            }

            $savedMessage = Message::create($messageData);

            // Save media metadata if available (WhatsApp only)
            if ($mediaData) {
                $savedMessage->setMeta('media_driver', $mediaData['driver']);
                $savedMessage->setMeta('media_url', $mediaData['url']);
                $savedMessage->setMeta('media_path', $mediaData['path']);
                $savedMessage->setMeta('media_mime_type', $mediaData['mime_type']);
                $savedMessage->setMeta('media_size', $mediaData['size']);
                $savedMessage->setMeta('media_original_size', $mediaData['original_size']);

                if (isset($mediaData['filename'])) {
                    $savedMessage->setMeta('media_filename', $mediaData['filename']);
                }

                if ($mediaData['original_size'] > 0 && $mediaData['size'] !== $mediaData['original_size']) {
                    $compressionRatio = round((1 - ($mediaData['size'] / $mediaData['original_size'])) * 100, 2);
                    $savedMessage->setMeta('media_compression_ratio', $compressionRatio);
                }

                Log::info('Media saved with message', [
                    'message_id'       => $savedMessage->id,
                    'media_driver'     => $mediaData['driver'],
                    'media_url'        => $mediaData['url'],
                    'compression_ratio'=> $compressionRatio ?? 0,
                ]);
            }

            Log::info('Message saved successfully', [
                'id'         => $savedMessage->id,
                'message_id' => $messageId,
                'type'       => $messageType,
                'platform'   => $this->platform,
                'from'       => $this->getFrom(),
                'has_media'  => !is_null($mediaData),
            ]);

            $contact = null;
            $identifier = $this->getFrom();

            if ($identifier) {
                try {
                    $contactService = new ContactService();

                    $contact = $contactService->createOrUpdateFromWebhook(
                        $identifier,
                        $contactName,
                        $this->channel
                    );

                    Log::info('Contact handled from webhook', [
                        'contact_id' => $contact->id,
                        'identifier' => $identifier,
                        'name'       => $contactName,
                        'channel_id' => $this->channel->id,
                    ]);

                } catch (\Throwable $e) {
                    Log::error('Failed to handle contact from webhook', [
                        'identifier' => $identifier,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            return [
                'message'      => $savedMessage,
                'contact'      => $contact,
                'is_duplicate' => false,
            ];

        } catch (\Throwable $e) {
            Log::error('SaveMessageHelper failed', [
                'error'      => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'platform'   => $this->platform,
                'trace'      => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get message ID based on platform
     */
    private function getMessageId(): ?string
    {
        return match ($this->platform) {
            'telegram' => ($this->message['chat']['id'] ?? '') . '_' . ($this->message['message_id'] ?? null),
            default    => $this->message['id'] ?? null, // whatsapp
        };
    }

    /**
     * Get message type based on platform
     */
    private function getMessageType(): string
    {
        return match ($this->platform) {
            'telegram' => $this->detectTelegramMessageType(),
            default    => $this->message['type'] ?? 'unknown', // whatsapp
        };
    }

    /**
     * Detect Telegram message type
     */
    private function detectTelegramMessageType(): string
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
     * Get sender based on platform
     */
    private function getFrom(): ?string
    {
        return match ($this->platform) {
            'telegram' => (string) ($this->message['from']['id'] ?? null),
            default    => $this->message['from'] ?? null, // whatsapp
        };
    }

    /**
     * Get recipient based on platform
     */
    private function getTo(): ?string
    {
        return match ($this->platform) {
            'telegram' => $this->channel->getMeta('telegram_bot_username'),
            default    => $this->metadata['display_phone_number'] // whatsapp
                            ?? $this->metadata['phone_number_id']
                            ?? null,
        };
    }

    /**
     * Get timestamp based on platform
     */
    private function getTimestamp()
    {
        return match ($this->platform) {
            'telegram' => isset($this->message['date'])
                            ? now()->setTimestamp($this->message['date'])
                            : now(),
            default    => isset($this->message['timestamp']) // whatsapp
                            ? now()->setTimestamp($this->message['timestamp'])
                            : now(),
        };
    }

    /**
     * Get contact name based on platform
     */
    private function getContactName(): ?string
    {
        return match ($this->platform) {
            'telegram' => trim(
                ($this->message['from']['first_name'] ?? '') . ' ' .
                ($this->message['from']['last_name'] ?? '')
            ) ?: ($this->message['from']['username'] ?? null),

            default => $this->extractWhatsAppContactName(), // whatsapp
        };
    }

    /**
     * Extract WhatsApp contact name from contacts array
     */
    private function extractWhatsAppContactName(): ?string
    {
        $contactName = null;

        foreach ($this->contacts as $contact) {
            if (($contact['wa_id'] ?? null) === ($this->message['from'] ?? null)) {
                $contactName = $contact['profile']['name'] ?? null;
                break;
            }
        }

        if (!$contactName && count($this->contacts) === 1) {
            $contactName = $this->contacts[0]['profile']['name'] ?? null;
        }

        if (!$contactName) {
            $contactName = $this->rawPayload['contact_name'] ?? null;
        }

        return $contactName;
    }

    /**
     * Extract message content based on platform and type
     */
    private function extractMessageContent(array $message): string
    {
        return match ($this->platform) {
            'telegram' => $this->extractTelegramContent($message),
            default    => $this->extractWhatsAppContent($message), // whatsapp
        };
    }

    /**
     * Extract Telegram message content
     */
    private function extractTelegramContent(array $message): string
    {
        $type = $this->detectTelegramMessageType();

        return match ($type) {
            'text' => $message['text'] ?? '',

            'image' => json_encode([
                'caption' => $message['caption'] ?? '',
                'file_id' => data_get(
                    $message,
                    'photo.' . (count($message['photo']) - 1) . '.file_id',
                    ''
                ),
            ]),

            'video' => json_encode([
                'caption'   => $message['caption'] ?? '',
                'file_id'   => $message['video']['file_id'] ?? '',
                'duration'  => $message['video']['duration'] ?? '',
                'mime_type' => $message['video']['mime_type'] ?? '',
            ]),

            'audio' => json_encode([
                'file_id'   => $message['audio']['file_id'] ?? '',
                'duration'  => $message['audio']['duration'] ?? '',
                'mime_type' => $message['audio']['mime_type'] ?? '',
                'title'     => $message['audio']['title'] ?? '',
            ]),

            'voice' => json_encode([
                'file_id'   => $message['voice']['file_id'] ?? '',
                'duration'  => $message['voice']['duration'] ?? '',
                'mime_type' => $message['voice']['mime_type'] ?? '',
            ]),

            'document' => json_encode([
                'file_id'   => $message['document']['file_id'] ?? '',
                'file_name' => $message['document']['file_name'] ?? '',
                'mime_type' => $message['document']['mime_type'] ?? '',
                'caption'   => $message['caption'] ?? '',
            ]),

            'sticker' => json_encode([
                'file_id'  => $message['sticker']['file_id'] ?? '',
                'emoji'    => $message['sticker']['emoji'] ?? '',
                'animated' => $message['sticker']['is_animated'] ?? false,
            ]),

            'location' => json_encode([
                'latitude'  => $message['location']['latitude'] ?? '',
                'longitude' => $message['location']['longitude'] ?? '',
            ]),

            'contact' => json_encode([
                'phone_number' => $message['contact']['phone_number'] ?? '',
                'first_name'   => $message['contact']['first_name'] ?? '',
                'last_name'    => $message['contact']['last_name'] ?? '',
            ]),

            default => json_encode(['type' => $type, 'raw' => $message]),
        };
    }

    /**
     * Extract WhatsApp message content
     */
    private function extractWhatsAppContent(array $message): string
    {
        $type = $message['type'] ?? 'unknown';

        return match ($type) {
            'text' => $message['text']['body'] ?? '',

            'image' => json_encode([
                'caption'   => $message['image']['caption'] ?? '',
                'mime_type' => $message['image']['mime_type'] ?? '',
                'sha256'    => $message['image']['sha256'] ?? '',
                'id'        => $message['image']['id'] ?? '',
            ]),

            'video' => json_encode([
                'caption'   => $message['video']['caption'] ?? '',
                'mime_type' => $message['video']['mime_type'] ?? '',
                'sha256'    => $message['video']['sha256'] ?? '',
                'id'        => $message['video']['id'] ?? '',
            ]),

            'audio' => json_encode([
                'mime_type' => $message['audio']['mime_type'] ?? '',
                'sha256'    => $message['audio']['sha256'] ?? '',
                'id'        => $message['audio']['id'] ?? '',
                'voice'     => $message['audio']['voice'] ?? false,
            ]),

            'document' => json_encode([
                'filename'  => $message['document']['filename'] ?? '',
                'caption'   => $message['document']['caption'] ?? '',
                'mime_type' => $message['document']['mime_type'] ?? '',
                'sha256'    => $message['document']['sha256'] ?? '',
                'id'        => $message['document']['id'] ?? '',
            ]),

            'sticker' => json_encode([
                'mime_type' => $message['sticker']['mime_type'] ?? '',
                'sha256'    => $message['sticker']['sha256'] ?? '',
                'id'        => $message['sticker']['id'] ?? '',
                'animated'  => $message['sticker']['animated'] ?? false,
            ]),

            'location' => json_encode([
                'latitude'  => $message['location']['latitude'] ?? '',
                'longitude' => $message['location']['longitude'] ?? '',
                'name'      => $message['location']['name'] ?? '',
                'address'   => $message['location']['address'] ?? '',
            ]),

            'contacts'    => json_encode($message['contacts'] ?? []),

            'button' => json_encode([
                'text'    => $message['button']['text'] ?? '',
                'payload' => $message['button']['payload'] ?? '',
            ]),

            'interactive' => json_encode([
                'type'         => $message['interactive']['type'] ?? '',
                'button_reply' => $message['interactive']['button_reply'] ?? null,
                'list_reply'   => $message['interactive']['list_reply'] ?? null,
            ]),

            'reaction' => json_encode([
                'message_id' => $message['reaction']['message_id'] ?? '',
                'emoji'      => $message['reaction']['emoji'] ?? '',
            ]),

            default => json_encode(['type' => $type, 'raw' => $message]),
        };
    }

    /**
     * Handle media download and storage using MediaStorageService (WhatsApp only)
     */
    private function handleMediaDownload(string $type): ?array
    {
        try {
            $mediaId = $this->getMediaId($type);

            if (!$mediaId) {
                Log::warning('No media ID found', ['type' => $type]);
                return null;
            }

            Log::info('Processing media download', [
                'media_id' => $mediaId,
                'type'     => $type,
            ]);

            $storageService = new MediaStorageService($this->channel);

            return $storageService->downloadAndStore(
                $mediaId,
                $type,
                $this->message[$type] ?? []
            );

        } catch (\Throwable $e) {
            Log::error('Media download handling failed', [
                'type'  => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get media ID from WhatsApp message based on type
     */
    private function getMediaId(string $type): ?string
    {
        return match ($type) {
            'image'    => $this->message['image']['id'] ?? null,
            'document' => $this->message['document']['id'] ?? null,
            'audio'    => $this->message['audio']['id'] ?? null,
            'video'    => $this->message['video']['id'] ?? null,
            'sticker'  => $this->message['sticker']['id'] ?? null,
            default    => null,
        };
    }
}