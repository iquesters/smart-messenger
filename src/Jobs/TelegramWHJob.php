<?php

namespace Iquesters\SmartMessenger\Jobs;

use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Jobs\MessageJobs\NewTelegramMessageJob;

class TelegramWHJob extends WHJob
{
    /**
     * Process Telegram webhook
     */
    protected function process(): void
    {
        $this->logMethodStart($this->ctx([
            'channel_uid' => $this->channelUid,
        ]));

        // Determine webhook type and dispatch appropriate job
        $webhookType = $this->determineWebhookType();

        $this->logInfo('Processing Telegram webhook' . $this->ctx([
            'channel_uid' => $this->channelUid,
            'type'        => $webhookType,
        ]));

        match ($webhookType) {
            'new_message' => $this->handleNewMessage(),
            'unknown'     => $this->logInfo('Unknown webhook type, ignoring' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ])),
            default => $this->logWarning('Unhandled webhook type' . $this->ctx([
                'channel_uid' => $this->channelUid,
                'type'        => $webhookType,
            ])),
        };

        $this->logMethodEnd($this->ctx([
            'channel_uid' => $this->channelUid,
            'type'        => $webhookType,
        ]));
    }

    /**
     * Determine the type of Telegram update
     */
    private function determineWebhookType(): string
    {
        $message = data_get($this->payload, 'message');

        if (!empty($message)) {
            return 'new_message';
        }

        return 'unknown';
    }

    /**
     * Handle new incoming message
     */
    private function handleNewMessage(): void
    {
        $message = data_get($this->payload, 'message');

        if (!$message) {
            $this->logInfo('No message in Telegram webhook' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ]));
            return;
        }

        $chatId  = data_get($message, 'chat.id');
        $fromId  = data_get($message, 'from.id');

        if (!$chatId || !$fromId) {
            $this->logInfo('Missing chat_id or from_id in Telegram message' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ]));
            return;
        }

        // Resolve and validate channel
        $channel = Channel::where('uid', $this->channelUid)
            ->where('status', Constants::ACTIVE)
            ->with(['metas', 'provider'])
            ->first();

        if (!$channel) {
            $this->logWarning('Telegram channel not found or inactive' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ]));
            return;
        }

        $this->logInfo('Channel validated, dispatching Telegram message processing' . $this->ctx([
            'channel_uid' => $this->channelUid,
            'channel_id'  => $channel->id,
            'chat_id'     => $chatId,
            'from_id'     => $fromId,
        ]));

        // Dispatch NewTelegramMessageJob
        NewTelegramMessageJob::dispatch(
            $channel,
            $message,
            $this->payload
        );

        $this->logInfo('NewTelegramMessageJob dispatched' . $this->ctx([
            'channel_id' => $channel->id,
            'message_id' => $message['message_id'] ?? 'unknown',
        ]));
    }
}