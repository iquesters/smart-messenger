<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;

class SendTelegramReplyJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array   $payload;

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->payload] = $arguments;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'payload_type'       => $this->payload['type'] ?? null,
        ]));

        $channel = $this->inboundMessage->channel;
        $chatId  = $this->payload['to_override'] ?? $this->inboundMessage->from;

        if (!$channel || !$chatId) {
            $this->logWarning('Missing channel or chat_id for Telegram reply' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'has_channel'        => !empty($channel),
                'chat_id'            => $chatId,
            ]));
            return;
        }

        $botToken = $channel->getMeta('telegram_bot_token');

        if (!$botToken) {
            $this->logWarning('Missing Telegram bot token' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return;
        }

        $requestPayload = $this->buildTelegramPayload($chatId);

        if (!$requestPayload) {
            $this->logWarning('Telegram request payload could not be built' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'payload_type'       => $this->payload['type'] ?? null,
            ]));
            return;
        }

        $endpoint = "https://api.telegram.org/bot{$botToken}/" . $this->resolveTelegramMethod();

        $response = $this->sendTelegramRequest($endpoint, $requestPayload);

        if (!$response->successful()) {
            $this->logError('Telegram send failed' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response'           => $response->json(),
            ]));
            return;
        }

        $tgMessageId = $response->json('result.message_id');
        $storedMessageId = $chatId . '_' . $tgMessageId;

        if (!$tgMessageId) {
            $this->logWarning('Telegram send succeeded without message_id' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response'           => $response->json(),
            ]));
            return;
        }

        $messageType = $this->payload['type'] ?? 'text';

        $outbound = Message::create([
            'channel_id'     => $channel->id,
            'integration_id' => $this->payload['integration_id']
                                    ?? $this->inboundMessage->integration_id,
            'message_id'     => $storedMessageId,
            'from'           => $channel->getMeta('telegram_bot_username'),
            'to'             => $chatId,
            'message_type'   => $messageType,
            'content'        => $this->buildContentForDatabase($messageType),
            'timestamp'      => now(),
            'status'         => Constants::SENT,
            'raw_payload'    => $response->json(),
            'created_by'     => $this->payload['created_by_override']
                                    ?? $this->inboundMessage->created_by,
        ]);

        // Optional linkage if message is forwarded
        if (!empty($this->payload['_forwarded_from'])) {
            $outbound->setMeta('forwarded_from', $this->payload['_forwarded_from']);
        }

        if (!empty($this->payload['stored_media'])) {
            $media = $this->payload['stored_media'];

            $outbound->setMeta('media_driver', $media['driver']);
            $outbound->setMeta('media_path', $media['path']);
            $outbound->setMeta('media_url', $media['url']);
            $outbound->setMeta('mime_type', $media['mime_type']);
            $outbound->setMeta('media_size', (string) $media['size']);
        }

        $this->logInfo('Outbound Telegram message saved' . $this->ctx([
            'outbound_message_id' => $outbound->id,
            'tg_message_id'       => $tgMessageId,
            'chat_id'             => $chatId,
        ]));
    }

    /**
     * Build the Telegram API request payload
     */
    private function buildTelegramPayload(string|int $chatId): ?array
    {
        $type = $this->payload['type'] ?? 'text';

        if ($type === 'text') {
            return [
                'chat_id' => $chatId,
                'text'    => $this->payload['text'] ?? '',
            ];
        }

        if ($type === 'image') {
            $imageUrl = $this->payload['image_url']
                ?? ($this->payload['stored_media']['url'] ?? null);

            if (!$imageUrl) {
                $this->logWarning('Telegram image reply missing image URL' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            return array_filter([
                'chat_id' => $chatId,
                'photo'   => $imageUrl,
                'caption' => $this->payload['caption'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $this->logWarning('Unsupported Telegram reply type' . $this->ctx([
            'type' => $type,
        ]));

        return null;
    }

    private function sendTelegramRequest(string $endpoint, array $requestPayload)
    {
        $type = $this->payload['type'] ?? 'text';

        if ($type !== 'image') {
            return Http::post($endpoint, $requestPayload);
        }

        $storedPath = $this->payload['stored_media']['path'] ?? null;
        if (!$storedPath) {
            return Http::post($endpoint, $requestPayload);
        }

        $absolutePath = storage_path('app/public/' . $storedPath);
        if (!file_exists($absolutePath)) {
            $this->logWarning('Telegram image file missing for upload, falling back to URL send' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'path'               => $absolutePath,
            ]));

            return Http::post($endpoint, $requestPayload);
        }

        $fileHandle = fopen($absolutePath, 'r');

        try {
            return Http::attach('photo', $fileHandle, basename($absolutePath))
                ->post($endpoint, [
                    'chat_id' => $requestPayload['chat_id'],
                    'caption' => $requestPayload['caption'] ?? null,
                ]);
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }

    /**
     * Build content string for DB storage
     */
    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return $this->payload['text'] ?? '';
        }

        if ($type === 'image') {
            return json_encode([
                'caption'   => $this->payload['caption'] ?? '',
                'image_url' => $this->payload['image_url']
                    ?? ($this->payload['stored_media']['url'] ?? null),
            ]);
        }

        return json_encode($this->payload);
    }

    private function resolveTelegramMethod(): string
    {
        return match ($this->payload['type'] ?? 'text') {
            'image' => 'sendPhoto',
            default => 'sendMessage',
        };
    }
}
