<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;

class NewMessageJob extends BaseJob
{
    protected Channel $channel;
    protected array $message;
    protected array $rawPayload;
    protected ?array $metadata;
    protected array $contacts;

    protected function initialize(...$arguments): void
    {
        [
            $channel,
            $message,
            $rawPayload,
            $metadata,
            $contacts
        ] = $arguments;

        $this->channel = $channel;
        $this->message = $message;
        $this->rawPayload = $rawPayload;
        $this->metadata = $metadata;
        $this->contacts = $contacts ?? [];
    }

    /**
     * Handle the job - Orchestrate the message processing flow
     */
    public function process(): void
    {
        try {
            Log::info('Processing new message orchestration', [
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown'
            ]);

            // Step 1: Save the message by creating instance and calling handle directly
            $saveMessageJob = new SaveMessageHelper(
                $this->channel,
                $this->message,
                $this->rawPayload,
                $this->metadata,
                $this->contacts
            );

            $result = $saveMessageJob->process();

            $savedMessage = $result['message'];
            $contact = $result['contact'];

            Log::debug('Saved message details', [
                'saved_message' => $savedMessage ? $savedMessage->id : null
            ]);

            if (!$savedMessage) {
                Log::warning('Message could not be saved, stopping processing');
                return;
            }

            Log::info('Message saved, forwarding to chatbot', [
                'saved_message_id' => $savedMessage->id,
                'message_id' => $savedMessage->message_id
            ]);

            // Step 2: Forward to chatbot (asynchronously)
            ForwardToChatbotJob::dispatch(
                $savedMessage,
                $this->rawPayload,
                $contact
            );

        } catch (\Throwable $e) {
            Log::error('NewMessageJob failed', [
                'error' => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}