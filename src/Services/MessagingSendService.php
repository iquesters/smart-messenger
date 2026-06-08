<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\VideoConversionService;

class MessagingSendService
{
    public function __construct(
        protected MessagingDataService $messagingDataService,
        protected ?VideoConversionService $videoConversionService = null,
    ) {
        $this->videoConversionService ??= new VideoConversionService();
    }

    public function sendMessage(
        Channel $profile,
        string $to,
        ?string $messageText,
        ?UploadedFile $media,
        int $userId
    ): Message {
        $providerSlug = strtolower((string) ($profile->provider?->small_name ?? 'whatsapp'));

        if ($providerSlug === 'telegram') {
            return $this->sendTelegramMessage($profile, $to, $messageText, $media, $userId);
        }

        return $this->sendWhatsAppMessage($profile, $to, $messageText, $media, $userId);
    }

    public function sendText(Channel $profile, string $to, string $messageText, int $userId): Message
    {
        return $this->sendMessage($profile, $to, $messageText, null, $userId);
    }

    protected function sendTelegramText(Channel $profile, string $to, string $messageText, int $userId): Message
    {
        $botToken = $profile->getMeta('telegram_bot_token');

        if (!$botToken) {
            throw new InvalidArgumentException('Telegram credentials missing');
        }

        $response = Http::post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            [
                'chat_id' => $to,
                'text' => $messageText,
            ]
        );

        if (!$response->successful() || !$response->json('ok')) {
            Log::error('Telegram send failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new \RuntimeException('Telegram send failed');
        }

        return $this->createOutboundMessage(
            $profile,
            $this->formatTelegramMessageId($to, (string) $response->json('result.message_id')),
            $to,
            'text',
            $messageText,
            $response->json(),
            $userId
        );
    }

    protected function sendTelegramMessage(
        Channel $profile,
        string $to,
        ?string $messageText,
        ?UploadedFile $media,
        int $userId
    ): Message {
        if (!$media) {
            if (blank($messageText)) {
                throw new InvalidArgumentException('Message text is required');
            }

            return $this->sendTelegramText($profile, $to, $messageText, $userId);
        }

        $botToken = $profile->getMeta('telegram_bot_token');

        if (!$botToken) {
            throw new InvalidArgumentException('Telegram credentials missing');
        }

        $messageText = $messageText ?? '';
        $mimeType = $media->getMimeType() ?: 'application/octet-stream';
        $mediaType = $this->resolveSupportedMediaType($mimeType);

        if (!$mediaType) {
            throw new InvalidArgumentException('Unsupported media type');
        }

        ['path' => $storedPath, 'url' => $mediaUrl] = $this->storeUploadedMedia($media);

        $response = $this->uploadLocalMediaToTelegram(
            $botToken,
            $mediaType,
            $storedPath,
            array_filter([
                'chat_id' => $to,
                'caption' => $messageText !== '' ? $messageText : null,
            ], static fn ($value) => $value !== null && $value !== '')
        );

        if (!$response->successful() || !$response->json('ok')) {
            Log::error('Telegram media send failed', [
                'status' => $response->status(),
                'response' => $response->json(),
                'media_type' => $mediaType,
            ]);

            throw new \RuntimeException('Telegram media send failed');
        }

        $content = [
            'caption' => $messageText,
            'media_url' => $mediaUrl,
        ];

        if ($mediaType === 'image') {
            $content['file_id'] = data_get(
                $response->json(),
                'result.photo.' . (count((array) data_get($response->json(), 'result.photo', [])) - 1) . '.file_id'
            );
        }

        if ($mediaType === 'video') {
            $content['file_id'] = data_get($response->json(), 'result.video.file_id');
            $content['duration'] = data_get($response->json(), 'result.video.duration');
            $content['mime_type'] = data_get($response->json(), 'result.video.mime_type', $mimeType);
        }

        $message = $this->createOutboundMessage(
            $profile,
            $this->formatTelegramMessageId($to, (string) $response->json('result.message_id')),
            $to,
            $mediaType,
            json_encode($content),
            $response->json(),
            $userId
        );

        $this->storeMediaMeta($message, $storedPath, $mediaUrl, $mimeType, $media->getSize());

        return $message;
    }

    protected function sendWhatsAppText(Channel $profile, string $to, string $messageText, int $userId): Message
    {
        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            throw new InvalidArgumentException('WhatsApp credentials missing');
        }

        $response = Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $messageText,
                ],
            ]
        );

        if (!$response->successful()) {
            Log::error('WhatsApp send failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new \RuntimeException('WhatsApp send failed');
        }

        return $this->createOutboundMessage(
            $profile,
            (string) data_get($response->json(), 'messages.0.id'),
            $to,
            'text',
            $messageText,
            $response->json(),
            $userId
        );
    }

    protected function sendWhatsAppMessage(
        Channel $profile,
        string $to,
        ?string $messageText,
        ?UploadedFile $media,
        int $userId
    ): Message {
        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            throw new InvalidArgumentException('WhatsApp credentials missing');
        }

        $hasMedia = $media !== null;
        $messageText = $messageText ?? '';
        $mediaType = null;
        $storedPath = null;
        $mediaUrl = null;
        $whatsAppMediaId = null;
        $mimeType = null;
        $messageUid = (string) Str::uuid();

        try {
            if ($hasMedia) {
                $mimeType = $media->getMimeType() ?: 'application/octet-stream';
                ['path' => $storedPath, 'url' => $mediaUrl] = $this->storeUploadedMedia($media);
                $mediaType = $this->resolveSupportedMediaType($mimeType);

                if (!$mediaType) {
                    throw new InvalidArgumentException('Unsupported media type: ' . $mimeType);
                }

                $conversionPerformed = false;

                if ($mediaType === 'video') {
                    $absolutePath = storage_path('app/public/' . $storedPath);

                    $this->videoConversionService->submit($messageUid, $absolutePath);
                    $result = $this->videoConversionService->poll($messageUid);

                    $storedPath = $result['path'];
                    $mimeType = 'video/mp4';
                    $conversionPerformed = true;

                    Log::info('Video conversion completed via watch-folder', [
                        'job_id'   => $messageUid,
                        'progress' => $result['progress'],
                    ]);
                }

                $this->validateMediaSize($mimeType, $media->getSize());

                try {
                    $whatsAppMediaId = $this->uploadLocalMediaToWhatsApp($profile, $storedPath, $mimeType);
                } finally {
                    if ($conversionPerformed) {
                        @unlink($storedPath);
                    }
                }

                if (!$whatsAppMediaId) {
                    throw new \RuntimeException('WhatsApp media upload failed');
                }
            } elseif (blank($messageText)) {
                throw new InvalidArgumentException('Message text is required when media is not attached');
            }

            $payload = $hasMedia
                ? [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => $mediaType,
                    $mediaType => [
                        'id' => $whatsAppMediaId,
                        'caption' => $messageText,
                    ],
                ]
                : [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'body' => $messageText,
                    ],
                ];

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                $payload
            );

            if (!$response->successful()) {
                throw new \RuntimeException('WhatsApp API send failed: HTTP ' . $response->status() . ' ' . json_encode($response->json()));
            }

            $waMessageId = data_get($response->json(), 'messages.0.id', $messageUid);

            $message = $this->createOutboundMessage(
                $profile,
                (string) data_get($response->json(), 'messages.0.id'),
                $to,
                $hasMedia ? $mediaType : 'text',
                $hasMedia
                    ? json_encode([
                        'caption' => $messageText,
                        'media_url' => $mediaUrl,
                        'whatsapp_media_id' => $whatsAppMediaId,
                        'wa_message_id' => $waMessageId,
                    ])
                    : $messageText,
                $response->json(),
                $userId
            );

            if ($hasMedia) {
                $message->setMeta('whatsapp_media_id', (string) $whatsAppMediaId);
                $this->storeMediaMeta($message, $storedPath, $mediaUrl, $mimeType, $media->getSize());
            }

            return $message;
        } catch (\Throwable $e) {
            $this->saveFailedWhatsAppMessage($profile, $to, $messageText, $mediaType ?? 'text', $userId, $e, [
                'has_media' => $hasMedia,
                'stored_path' => $storedPath,
                'media_url' => $mediaUrl,
                'mime_type' => $mimeType,
                'media_type' => $mediaType,
                'whatsapp_media_id' => $whatsAppMediaId,
                'message_uid' => $messageUid,
            ]);
            throw $e;
        }
    }

    protected function saveFailedWhatsAppMessage(
        Channel $profile,
        string $to,
        string $messageText,
        string $mediaType,
        int $userId,
        \Throwable $exception,
        array $context = [],
    ): void {
        try {
            $errorPayload = [
                'error' => $exception->getMessage(),
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'context' => $context,
                'failed_at' => now()->toIso8601String(),
            ];

            Message::create([
                'channel_id'    => $profile->id,
                'message_id'    => (string) Str::uuid(),
                'from'          => $this->messagingDataService->getProfileMessagingIdentifier($profile),
                'to'            => $to,
                'message_type'  => $mediaType,
                'content'       => $messageText ?: json_encode($context),
                'timestamp'     => now(),
                'status'        => Constants::FAILED,
                'raw_payload'   => $errorPayload,
                'created_by'    => $userId,
            ]);

            Log::error('WhatsApp message failed, saved FAILED record', [
                'error' => $exception->getMessage(),
                'channel_id' => $profile->id,
                'to' => $to,
            ]);
        } catch (\Throwable $logError) {
            Log::error('Failed to save FAILED message record', [
                'error' => $logError->getMessage(),
                'original_error' => $exception->getMessage(),
            ]);
        }
    }

    protected function resolveSupportedMediaType(string $mimeType): ?string
    {
        if (in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            return 'image';
        }

        if (in_array($mimeType, ['video/mp4', 'video/3gpp'], true)) {
            return 'video';
        }

        return null;
    }

    protected function resolveTelegramSendMethod(string $mediaType): string
    {
        return match ($mediaType) {
            'image' => 'sendPhoto',
            'video' => 'sendVideo',
            default => 'sendMessage',
        };
    }

    protected function storeUploadedMedia(UploadedFile $media): array
    {
        $storedPath = $media->store('media/uploads', 'public');

        return [
            'path' => $storedPath,
            'url' => asset('storage/' . $storedPath),
        ];
    }

    protected function createOutboundMessage(
        Channel $profile,
        string $messageId,
        string $to,
        string $messageType,
        string $content,
        array $rawPayload,
        int $userId
    ): Message {
        return Message::create([
            'channel_id' => $profile->id,
            'message_id' => $messageId,
            'from' => $this->messagingDataService->getProfileMessagingIdentifier($profile),
            'to' => $to,
            'message_type' => $messageType,
            'content' => $content,
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => $rawPayload,
            'created_by' => $userId,
        ]);
    }

    protected function storeMediaMeta(
        Message $message,
        string $storedPath,
        string $mediaUrl,
        string $mimeType,
        int|string|null $mediaSize
    ): void {
        $message->setMeta('media_url', $mediaUrl);
        $message->setMeta('media_path', $storedPath);
        $message->setMeta('media_mime_type', $mimeType);
        $message->setMeta('media_size', (string) $mediaSize);
    }

    protected function formatTelegramMessageId(string $to, string $telegramMessageId): string
    {
        return $to . '_' . $telegramMessageId;
    }

    protected function validateMediaSize(string $mimeType, int|false $fileSize): void
    {
        if ($fileSize === false) {
            return;
        }

        $limits = [
            'image' => 16 * 1024 * 1024,
            'video' => 64 * 1024 * 1024,
        ];

        $type = $this->resolveSupportedMediaType($mimeType);

        if ($type && isset($limits[$type]) && $fileSize > $limits[$type]) {
            throw new InvalidArgumentException(
                "{$type} file size {$fileSize} bytes exceeds WhatsApp limit of {$limits[$type]} bytes"
            );
        }
    }

    protected function uploadLocalMediaToWhatsApp(Channel $profile, string $path, string $mimeType): ?string
    {
       $absolutePath = (str_starts_with($path, '/') || str_starts_with($path, 'C:') || preg_match('/^[A-Za-z]:/', $path)) 
            ? $path 
            : storage_path('app/public/' . $path);

        if (!file_exists($absolutePath)) {
            Log::error('Media file not found for WhatsApp upload', [
                'path' => $absolutePath,
            ]);

            return null;
        }

        $fileHandle = fopen($absolutePath, 'r');

        try {
            $response = Http::withToken($profile->getMeta('system_user_token'))
                ->attach('file', $fileHandle, basename($absolutePath))
                ->post(
                    'https://graph.facebook.com/v18.0/' . $profile->getMeta('whatsapp_phone_number_id') . '/media',
                    [
                        'messaging_product' => 'whatsapp',
                        'type' => $mimeType,
                    ]
                );
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }

        if (!$response->successful()) {
            Log::error('WhatsApp media upload failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return null;
        }

        return $response->json('id');
    }

    protected function uploadLocalMediaToTelegram(
        string $botToken,
        string $mediaType,
        string $path,
        array $payload
    ) {
        $absolutePath = storage_path('app/public/' . $path);

        if (!file_exists($absolutePath)) {
            Log::error('Media file not found for Telegram upload', [
                'path' => $absolutePath,
                'media_type' => $mediaType,
            ]);

            throw new \RuntimeException('Telegram media file missing');
        }

        $field = $mediaType === 'image' ? 'photo' : 'video';
        $fileHandle = fopen($absolutePath, 'r');

        try {
            return Http::attach($field, $fileHandle, basename($absolutePath))
                ->post(
                    "https://api.telegram.org/bot{$botToken}/" . $this->resolveTelegramSendMethod($mediaType),
                    $payload
                );
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }
}
