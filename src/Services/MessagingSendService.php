<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;

class MessagingSendService
{
    public function __construct(
        protected MessagingDataService $messagingDataService,
    ) {
    }

    public function sendText(Channel $profile, string $to, string $messageText, int $userId): Message
    {
        $providerSlug = strtolower((string) ($profile->provider?->small_name ?? 'whatsapp'));

        if ($providerSlug === 'telegram') {
            return $this->sendTelegramText($profile, $to, $messageText, $userId);
        }

        return $this->sendWhatsAppText($profile, $to, $messageText, $userId);
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
        $storedTelegramMessageId = 'tg_out_' . $to . '_' . $telegramMessageId;

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
}
