<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

class PollBotResponseJob extends BaseJob
{
    protected Message $message;
    protected string $chatbotMessageId;

    protected function initialize(...$arguments): void
    {
        [$message, $chatbotMessageId] = $arguments;

        $this->message = $message;
        $this->chatbotMessageId = $chatbotMessageId;
    }

    /**
     * Handle the job - Poll chatbot for response
     */
    public function process(): void
    {
        try {
            Log::info('Starting to poll chatbot for response', [
                'message_id' => $this->message->id,
                'chatbot_message_id' => $this->chatbotMessageId
            ]);

            $start = microtime(true);
            $pollCount = 0;
            $maxPollTime = 20; // 20 seconds max

            while ((microtime(true) - $start) < $maxPollTime) {
                $pollCount++;

                $poll = Http::get("https://api.nams.site/messages/{$this->chatbotMessageId}/response");
                $status = $poll->status();
                $body = $poll->json();

                Log::debug('Poll attempt', [
                    'poll_count' => $pollCount,
                    'status' => $status,
                    'ready' => $body['ready'] ?? null
                ]);

                // Still processing
                if ($status === 202 || ($status === 200 && empty($body['ready']))) {
                    sleep(1);
                    continue;
                }

                // Terminal errors
                if (in_array($status, [400, 404, 409])) {
                    Log::error('Polling terminal error', [
                        'message_id' => $this->message->id,
                        'chatbot_message_id' => $this->chatbotMessageId,
                        'status' => $status,
                        'response' => $body,
                        'poll_count' => $pollCount
                    ]);
                    break;
                }

                // Completed successfully
                if ($status === 200 && ($body['ready'] ?? false) === true) {
                    if (!empty($body['failed'])) {
                        Log::error('Bot processing failed', [
                            'message_id' => $this->message->id,
                            'error' => $body['inbound']['error_message'] ?? null
                        ]);
                        break;
                    }

                    $replyText = data_get($body, 'outbound.content');

                    if (!$replyText) {
                        Log::warning('Reply text missing from bot response', [
                            'message_id' => $this->message->id,
                            'response' => $body
                        ]);
                        break;
                    }

                    Log::info('Bot response received, dispatching send reply job', [
                        'message_id' => $this->message->id,
                        'poll_count' => $pollCount,
                        'reply_length' => strlen($replyText)
                    ]);

                    // Dispatch SendWhatsAppReplyJob
                    SendWhatsAppReplyJob::dispatch(
                        $this->message,
                        $replyText
                    );

                    break;
                }

                sleep(1);
            }

            // Check for timeout
            if ((microtime(true) - $start) >= $maxPollTime) {
                Log::warning('Polling timeout', [
                    'message_id' => $this->message->id,
                    'chatbot_message_id' => $this->chatbotMessageId,
                    'poll_count' => $pollCount,
                    'duration' => round(microtime(true) - $start, 2)
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('PollBotResponseJob failed', [
                'message_id' => $this->message->id,
                'chatbot_message_id' => $this->chatbotMessageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}