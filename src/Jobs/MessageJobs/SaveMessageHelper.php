<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\SmartMessenger\Services\MediaStorageService;
// use Iquesters\SmartMessenger\Events\MessageReceivedEvent;

class SaveMessageHelper
{
    protected Channel $channel;
    protected array $message;
    protected array $rawPayload;
    protected ?array $metadata;
    protected array $contacts;

    /**
     * Constructor
     */
    public function __construct(
        Channel $channel,
        array $message,
        array $rawPayload,
        ?array $metadata,
        array $contacts = []
    ) {
        $this->channel = $channel;
        $this->message = $message;
        $this->rawPayload = $rawPayload;
        $this->metadata = $metadata;
        $this->contacts = $contacts;
    }

    /**
     * @return array{message: Message, contact: ?\Iquesters\SmartMessenger\Models\Contact}
     */
    public function process(): array
    {
        try {
            $messageId = $this->message['id'] ?? null;

            if (!$messageId) {
                Log::warning('Message without ID', ['message' => $this->message]);
                throw new \Exception('Message ID is required');
            }

            // Prevent duplicates
            $existingMessage = Message::where('message_id', $messageId)->first();
            if ($existingMessage) {
                Log::info('Duplicate message, returning existing', ['message_id' => $messageId]);
                return $existingMessage;
            }

            // Extract contact info
            $contactName = null;
            foreach ($this->contacts as $contact) {
                if (($contact['wa_id'] ?? null) === ($this->message['from'] ?? null)) {
                    $contactName = $contact['profile']['name'] ?? null;
                    break;
                }
            }

            $messageType = $this->message['type'] ?? 'unknown';

            // Handle media download if applicable
            $mediaData = null;
            if (in_array($messageType, ['image', 'document', 'audio', 'video', 'sticker'])) {
                $mediaData = $this->handleMediaDownload($messageType);
                
                if (!$mediaData) {
                    Log::warning('Failed to download media, continuing without it', [
                        'message_id' => $messageId,
                        'type' => $messageType
                    ]);
                }
            }

            // Prepare message data
            $messageData = [
                'channel_id' => $this->channel->id,
                'message_id' => $messageId,
                'from' => $this->message['from'] ?? null,
                'to' => $this->metadata['display_phone_number'] 
                        ?? $this->metadata['phone_number_id'] 
                        ?? null,
                'message_type' => $messageType,
                'content' => $this->extractMessageContent($this->message),
                'timestamp' => isset($this->message['timestamp']) 
                                ? now()->setTimestamp($this->message['timestamp']) 
                                : now(),
                'status' => Constants::RECEIVED,
                'raw_payload' => $this->rawPayload,
            ];

            if ($contactName) {
                $messageData['raw_payload']['contact_name'] = $contactName;
            }

            // Add media URL to raw payload if available
            // if ($mediaData) {
            //     $messageData['raw_payload']['media_downloaded_url'] = $mediaData['url'];
            // }

            $savedMessage = Message::create($messageData);

            // Save media metadata if available
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

                // Calculate compression ratio if available
                if ($mediaData['original_size'] > 0 && $mediaData['size'] !== $mediaData['original_size']) {
                    $compressionRatio = round((1 - ($mediaData['size'] / $mediaData['original_size'])) * 100, 2);
                    $savedMessage->setMeta('media_compression_ratio', $compressionRatio);
                }

                Log::info('Media saved with message', [
                    'message_id' => $savedMessage->id,
                    'media_driver' => $mediaData['driver'],
                    'media_url' => $mediaData['url'],
                    'compression_ratio' => $compressionRatio ?? 0
                ]);
            }
            
            // broadcast(new MessageReceivedEvent($savedMessage));
            
            Log::info('Message saved successfully', [
                'id' => $savedMessage->id,
                'message_id' => $messageId,
                'type' => $messageType,
                'from' => $this->message['from'],
                'has_media' => !is_null($mediaData)
            ]);

            $contact = null;
            // Handle contact
            $identifier = $this->message['from'] ?? null;

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
                        'channel_id' => $this->channel->id,
                    ]);

                } catch (\Throwable $e) {
                    Log::error('Failed to handle contact from webhook', [
                        'identifier' => $identifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return [
                'message' => $savedMessage,
                'contact' => $contact,
            ];


        } catch (\Throwable $e) {
            Log::error('SaveMessageJob failed', [
                'error' => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle media download and storage using MediaStorageService
     */
    private function handleMediaDownload(string $type): ?array
    {
        try {
            // Get media ID from message
            $mediaId = $this->getMediaId($type);
            
            if (!$mediaId) {
                Log::warning('No media ID found', ['type' => $type]);
                return null;
            }

            Log::info('Processing media download', [
                'media_id' => $mediaId,
                'type' => $type
            ]);

            // Use MediaStorageService to handle download and storage
            $storageService = new MediaStorageService($this->channel);
            
            return $storageService->downloadAndStore(
                $mediaId,
                $type,
                $this->message[$type] ?? []
            );

        } catch (\Throwable $e) {
            Log::error('Media download handling failed', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Get media ID from message based on type
     */
    private function getMediaId(string $type): ?string
    {
        return match($type) {
            'image' => $this->message['image']['id'] ?? null,
            'document' => $this->message['document']['id'] ?? null,
            'audio' => $this->message['audio']['id'] ?? null,
            'video' => $this->message['video']['id'] ?? null,
            'sticker' => $this->message['sticker']['id'] ?? null,
            default => null,
        };
    }

    /**
     * Extract message content based on type
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