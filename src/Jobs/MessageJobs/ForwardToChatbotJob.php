<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

class ForwardToChatbotJob extends BaseJob
{
    protected Message $message;
    protected array $rawPayload;

    protected function initialize(...$arguments): void
    {
        [$message, $rawPayload] = $arguments;

        $this->message = $message;
        $this->rawPayload = $rawPayload;
    }

    /**
     * Handle the job - Forward message to chatbot API
     */
    public function process(): void
    {
        try {
            Log::info('Forwarding message to chatbot API', [
                'message_id' => $this->message->id,
                'from' => $this->message->from
            ]);

            // Call chatbot API
            $response = Http::post('https://api.nams.site/webhook/whatsapp/v1', $this->rawPayload);

            Log::info('Chatbot API response received', [
                'message_id' => $this->message->id,
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            if (!$response->successful()) {
                Log::error('Chatbot API call failed', [
                    'message_id' => $this->message->id,
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return;
            }

            $chatbotMessageId = $response->json('message_id');

            if (!$chatbotMessageId) {
                Log::error('No message_id returned from chatbot', [
                    'message_id' => $this->message->id,
                    'response' => $response->json()
                ]);
                return;
            }

            Log::info('Chatbot accepted message, dispatching poll job', [
                'message_id' => $this->message->id,
                'chatbot_message_id' => $chatbotMessageId
            ]);

            // Dispatch PollBotResponseJob to wait for response
            PollBotResponseJob::dispatch(
                $this->message,
                $chatbotMessageId
            );

        } catch (\Throwable $e) {
            Log::error('ForwardToChatbotJob failed', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}