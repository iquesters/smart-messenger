<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;

class NewTelegramMessageJob extends BaseJob
{
    protected Channel $channel;
    protected array   $message;
    protected array   $rawPayload;

    protected function initialize(...$arguments): void
    {
        [
            $this->channel,
            $this->message,
            $this->rawPayload,
        ] = $arguments;
    }

    /**
     * Main orchestrator
     */
    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'channel_id' => $this->channel->id,
            'message_id' => $this->message['message_id'] ?? 'unknown',
        ]));

        try {
            /**
             * Step 1 — Save message and handle contact via SaveMessageHelper
             */
            $saveHelper = new SaveMessageHelper(
                $this->channel,
                $this->message,
                $this->rawPayload
            );

            $result = $saveHelper->process();

            $savedMessage = $result['message'];
            $contact      = $result['contact'];
            $isDuplicate  = (bool) ($result['is_duplicate'] ?? false);

            if ($isDuplicate) {
                $this->logInfo('Duplicate Telegram message detected, skipping downstream dispatch' . $this->ctx([
                    'channel_id'       => $this->channel->id,
                    'message_id'       => $this->message['message_id'] ?? 'unknown',
                    'saved_message_id' => $savedMessage->id ?? null,
                ]));
                return;
            }

            if (!$savedMessage) {
                $this->logWarning('Telegram message could not be saved, stopping processing' . $this->ctx([
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->message['message_id'] ?? 'unknown',
                ]));
                return;
            }

            /**
             * Step 2 — Forward to chatbot
             */
            ForwardToChatbotJob::dispatch(
                $savedMessage,
                $this->rawPayload,
                $contact
            );

            $this->logInfo('ForwardToChatbotJob dispatched for Telegram message' . $this->ctx([
                'channel_id'       => $this->channel->id,
                'saved_message_id' => $savedMessage->id,
            ]));

        } catch (\Throwable $e) {
            $this->logError('NewTelegramMessageJob failed' . $this->ctx([
                'error'      => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['message_id'] ?? 'unknown',
            ]));
            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['message_id'] ?? 'unknown',
            ]));
        }
    }
}