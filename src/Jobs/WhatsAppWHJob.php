<?php

namespace Iquesters\SmartMessenger\Jobs;

use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Jobs\MessageJobs\NewMessageJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\StatusUpdateJob;

class WhatsAppWHJob extends WHJob
{
    /**
     * Process WhatsApp webhook
     */
    protected function process(): void
    {
        // Determine webhook type and dispatch appropriate job
        $webhookType = $this->determineWebhookType();

        Log::info('Processing WhatsApp webhook', [
            'channel_uid' => $this->channelUid,
            'type' => $webhookType
        ]);
        
        match ($webhookType) {
            'status_update' => $this->handleStatusUpdate(),
            'new_message' => $this->handleNewMessage(),
            'unknown' => Log::info('Unknown webhook type, ignoring'),
            default => Log::warning('Unhandled webhook type', ['type' => $webhookType])
        };
    }

    /**
     * Determine the type of webhook
     */
    private function determineWebhookType(): string
    {
        // Check for status updates
        $statuses = data_get($this->payload, 'entry.0.changes.0.value.statuses', []);
        if (!empty($statuses)) {
            return 'status_update';
        }

        // Check for messages
        $messages = data_get($this->payload, 'entry.0.changes.0.value.messages', []);
        if (!empty($messages)) {
            return 'new_message';
        }

        return 'unknown';
    }

    /**
     * Handle status update webhook
     */
    private function handleStatusUpdate(): void
    {
        $statuses = data_get($this->payload, 'entry.0.changes.0.value.statuses', []);

        if (empty($statuses)) {
            return;
        }

        // Dispatch StatusUpdateJob
        StatusUpdateJob::dispatch($statuses);

        Log::info('StatusUpdateJob dispatched', [
            'status_count' => count($statuses)
        ]);
    }

    /**
     * Handle new message webhook
     */
    private function handleNewMessage(): void
    {
        $phoneNumberId = data_get($this->payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        if (!$phoneNumberId) {
            Log::info('No phone_number_id in message webhook');
            return;
        }

        $waUserNumber = data_get($this->payload, 'entry.0.changes.0.value.messages.0.from');

        if (!$waUserNumber) {
            Log::info('No sender number in webhook');
            return;
        }

        // Resolve and validate channel
        $channel = Channel::where('uid', $this->channelUid)
            ->where('status', Constants::ACTIVE)
            ->with(['metas', 'provider'])
            ->first();

        if (!$channel) {
            Log::warning('Channel not found or inactive', ['channel_uid' => $this->channelUid]);
            return;
        }

        // Validate phone number ID
        $phoneNumberIdMeta = $channel->getMeta('whatsapp_phone_number_id');

        if ($phoneNumberIdMeta !== $phoneNumberId) {
            Log::warning('Phone number ID mismatch', [
                'channel_uid' => $this->channelUid,
                'expected_phone_id' => $phoneNumberIdMeta,
                'received_phone_id' => $phoneNumberId
            ]);
            return;
        }

        Log::info('Channel validated, dispatching message processing', [
            'channel_uid' => $this->channelUid,
            'channel_id' => $channel->id,
            'phone_number_id' => $phoneNumberId
        ]);

        // Dispatch NewMessageJob for each message
        $messages = data_get($this->payload, 'entry.0.changes.0.value.messages', []);
        $metadata = data_get($this->payload, 'entry.0.changes.0.value.metadata');
        $contacts = data_get($this->payload, 'entry.0.changes.0.value.contacts', []);

        foreach ($messages as $message) {
            NewMessageJob::dispatch(
                $channel,
                $message,
                $this->payload,
                $metadata,
                $contacts
            );

            Log::info('NewMessageJob dispatched', [
                'channel_id' => $channel->id,
                'message_id' => $message['id'] ?? 'unknown'
            ]);
        }
    }
}