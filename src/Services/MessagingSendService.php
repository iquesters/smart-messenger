<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\VideoConversionService;

class MessagingSendService
{
    public function __construct(
        protected MessagingDataService $messagingDataService,
    ) {
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
            if ($media) {
                throw new InvalidArgumentException('Telegram media sending is not supported yet');
            }

            if (blank($messageText)) {
                throw new InvalidArgumentException('Message text is required');
            }

            return $this->sendTelegramText($profile, $to, $messageText, $userId);
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

        $telegramMessageId = $response->json('result.message_id');
        $storedTelegramMessageId = $to . '_' . $telegramMessageId;

        return Message::create([
            'channel_id' => $profile->id,
            'message_id' => $storedTelegramMessageId,
            'from' => $this->messagingDataService->getProfileMessagingIdentifier($profile),
            'to' => $to,
            'message_type' => 'text',
            'content' => $messageText,
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => $response->json(),
            'created_by' => $userId,
        ]);
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

        return Message::create([
            'channel_id' => $profile->id,
            'message_id' => data_get($response->json(), 'messages.0.id'),
            'from' => $this->messagingDataService->getProfileMessagingIdentifier($profile),
            'to' => $to,
            'message_type' => 'text',
            'content' => $messageText,
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => $response->json(),
            'created_by' => $userId,
        ]);
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

        if ($hasMedia) {
            $mimeType = $media->getMimeType() ?: 'application/octet-stream';
            $storedPath = $media->store('media/uploads', 'public');
            $mediaUrl = asset('storage/' . $storedPath);
            $mediaType = $this->resolveWhatsAppMediaType($mimeType);

            if (!$mediaType) {
                throw new InvalidArgumentException('Unsupported media type');
            }

            if ($mediaType === 'video') {
                $conversionService = new VideoConversionService();
                $absolutePath = storage_path('app/public/' . $storedPath);
                $jobId = $conversionService->generateJobId();

                $conversionService->submit($jobId, $absolutePath);
                $result = $conversionService->poll($jobId);

                $storedPath = $result['path'];
                $mimeType = 'video/mp4';

                Log::info('Video conversion completed via watch-folder', [
                    'job_id'   => $jobId,
                    'progress' => $result['progress'],
                ]);
            }

            $whatsAppMediaId = $this->uploadLocalMediaToWhatsApp($profile, $storedPath, $mimeType);

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
            Log::error('WhatsApp send failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new \RuntimeException('WhatsApp send failed');
        }

        $message = Message::create([
            'channel_id' => $profile->id,
            'message_id' => data_get($response->json(), 'messages.0.id'),
            'from' => $this->messagingDataService->getProfileMessagingIdentifier($profile),
            'to' => $to,
            'message_type' => $hasMedia ? $mediaType : 'text',
            'content' => $hasMedia
                ? json_encode([
                    'caption' => $messageText,
                    'media_url' => $mediaUrl,
                    'whatsapp_media_id' => $whatsAppMediaId,
                ])
                : $messageText,
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => $response->json(),
            'created_by' => $userId,
        ]);

        if ($hasMedia) {
            $message->setMeta('whatsapp_media_id', (string) $whatsAppMediaId);
            $message->setMeta('media_url', (string) $mediaUrl);
            $message->setMeta('media_path', (string) $storedPath);
            $message->setMeta('media_mime_type', (string) $mimeType);
            $message->setMeta('media_size', (string) $media->getSize());
        }

        return $message;
    }

    protected function resolveWhatsAppMediaType(string $mimeType): ?string
    {
        if (in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            return 'image';
        }

        if (in_array($mimeType, ['video/mp4', 'video/3gpp'], true)) {
            return 'video';
        }

        return null;
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
}
