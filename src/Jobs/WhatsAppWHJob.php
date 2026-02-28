<?php

namespace Iquesters\SmartMessenger\Jobs;

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
        $this->logMethodStart($this->ctx([
            'channel_uid' => $this->channelUid,
        ]));

        // Determine webhook type and dispatch appropriate job
        $webhookType = $this->determineWebhookType();

        $this->logInfo('Processing WhatsApp webhook' . $this->ctx([
            'channel_uid' => $this->channelUid,
            'type' => $webhookType
        ]));
        
        match ($webhookType) {
            'status_update' => $this->handleStatusUpdate(),
            'new_message' => $this->handleNewMessage(),
            'unknown' => $this->logInfo('Unknown webhook type, ignoring' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ])),
            default => $this->logWarning('Unhandled webhook type' . $this->ctx([
                'channel_uid' => $this->channelUid,
                'type' => $webhookType,
            ]))
        };

        $this->logMethodEnd($this->ctx([
            'channel_uid' => $this->channelUid,
            'type' => $webhookType,
        ]));
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

        $this->logInfo('StatusUpdateJob dispatched' . $this->ctx([
            'status_count' => count($statuses)
        ]));
    }

    /**
     * Handle new message webhook
     */
    private function handleNewMessage(): void
    {
        $phoneNumberId = data_get($this->payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        if (!$phoneNumberId) {
            $this->logInfo('No phone_number_id in message webhook' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ]));
            return;
        }

        $waUserNumber = data_get($this->payload, 'entry.0.changes.0.value.messages.0.from');

        if (!$waUserNumber) {
            $this->logInfo('No sender number in webhook' . $this->ctx([
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
            $this->logWarning('Channel not found or inactive' . $this->ctx([
                'channel_uid' => $this->channelUid,
            ]));
            return;
        }

        // Validate phone number ID
        $phoneNumberIdMeta = $channel->getMeta('whatsapp_phone_number_id');

        if ($phoneNumberIdMeta !== $phoneNumberId) {
            $this->logWarning('Phone number ID mismatch' . $this->ctx([
                'channel_uid' => $this->channelUid,
                'expected_phone_id' => $phoneNumberIdMeta,
                'received_phone_id' => $phoneNumberId
            ]));
            return;
        }

        $this->logInfo('Channel validated, dispatching message processing' . $this->ctx([
            'channel_uid' => $this->channelUid,
            'channel_id' => $channel->id,
            'phone_number_id' => $phoneNumberId
        ]));

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

            $this->logInfo('NewMessageJob dispatched' . $this->ctx([
                'channel_id' => $channel->id,
                'message_id' => $message['id'] ?? 'unknown'
            ]));
        }
    }
}