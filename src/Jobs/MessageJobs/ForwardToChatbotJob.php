<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;

class ForwardToChatbotJob extends BaseJob
{
    protected Message $message;
    protected array $rawPayload;
    protected ?Contact $contact;

    protected function initialize(...$arguments): void
    {
        [$message, $rawPayload, $contact] = $arguments;

        $this->message = $message;
        $this->rawPayload = $rawPayload;
        $this->contact = $contact;
    }

    /**
     * Handle the job - Forward message to chatbot API
     */
    public function process(): void
    {
        try {
            Log::info('Forwarding message to chatbot API', [
                'message_id' => $this->message->id,
                'from' => $this->message->from,
                'type' => $this->message->message_type
            ]);

            // Prepare payload for chatbot
            $payload = $this->preparePayload();
            Log::debug('Calling chatbot API with payload: '.json_encode($payload));  
            
            // Call chatbot API
            // $response = Http::post('https://api.nams.site/webhook/whatsapp/v1', $payload);
            $response = Http::post('http://localhost:8000/api/test/chatbot', $payload);
    
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

            // $chatbotMessageId = $response->json('message_id');

            // if (!$chatbotMessageId) {
            //     Log::error('No message_id returned from chatbot', [
            //         'message_id' => $this->message->id,
            //         'response' => $response->json()
            //     ]);
            //     return;
            // }

            // Log::info('Chatbot accepted message, dispatching poll job', [
            //     'message_id' => $this->message->id,
            //     'chatbot_message_id' => $chatbotMessageId
            // ]);

            // // Dispatch PollBotResponseJob to wait for response
            // PollBotResponseJob::dispatch(
            //     $this->message,
            //     $chatbotMessageId
            // );
            
            Log::info('Dispatching ProcessChatbotResponseJob', [
                'message_id' => $this->message->id
            ]);

            ProcessChatbotResponseJob::dispatch(
                $this->message,
                $response->json()
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

    /**
     * Prepare simplified payload for chatbot
     */
    private function preparePayload(): array
    {
        // Get integration UID from channel workflow
        $integrationUid = $this->getIntegrationUidFromWorkflow();

        $payload = [
            'integration_uid'    => $integrationUid,
            'contact_uid'        => $this->contact?->uid,
            'contact_identifier' => $this->contact?->identifier ?? $this->message->from,
            'contact_name'       => $this->contact?->name 
                                    ?? $this->rawPayload['contact_name'] 
                                    ?? null,
        ];

        // Add message text if available (for text messages)
        if ($this->message->message_type === 'text') {
            $payload['message'] = $this->message->content;
        }

        // Add file URL if message has media
        $mediaUrl = $this->message->getMeta('media_url');
        if ($mediaUrl) {
            $payload['file_url'] = $mediaUrl;
            $payload['file_type'] = $this->message->message_type; // image, document, audio, video
            $payload['mime_type'] = $this->message->getMeta('media_mime_type');
            
            // Add caption if available (for images, videos, documents)
            if (in_array($this->message->message_type, ['image', 'video', 'document'])) {
                $content = json_decode($this->message->content, true);
                if (isset($content['caption']) && !empty($content['caption'])) {
                    $payload['message'] = $content['caption'];
                }
            }
        }

        Log::info('Prepared chatbot payload', [
            'integration_uid' => $integrationUid,
            'contact_uid' => $this->contact?->uid,
            'contact_identifier' => $payload['contact_identifier'],
            'has_message' => isset($payload['message']),
            'has_file' => isset($payload['file_url']),
            'message_type' => $this->message->message_type
        ]);

        return $payload;
    }

    /**
     * Get integration UID from channel workflow
     * TODO: Implement logic to fetch from channel workflow configuration
     * For now, returns hardcoded value
     */
    private function getIntegrationUidFromWorkflow(): string
    {
        // TODO: Implement workflow integration UID retrieval
        // Example: $this->message->channel->workflow->integration_uid
        // Or: $this->message->channel->getMeta('workflow_integration_uid')
        
        return '01KENTHZSPTNY9F1QTERGHTQYD';
    }
}