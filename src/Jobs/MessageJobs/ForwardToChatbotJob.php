<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\Integration\Models\Integration;
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
            // $response = Http::post('http://localhost:8000/api/test/chatbot', $payload);

            $response = Http::timeout(0) // infinite wait
            ->withOptions([
                'connect_timeout' => 10,
                'read_timeout' => 0,
            ])
            ->post('https://api-chatbot.iquesters.com/api/chat/v1', $payload);
    
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
        $companyId = $this->getCompanyId();
        $integrationUid = $this->getIntegrationUidFromWorkflow();

        $payload = [
            // 'company_id'    => $companyId,
            // 'integration_uid' => $integrationUid,
            'integration_id' => $integrationUid,
            'contact_uid'        => $this->contact?->uid,
            'contact_identifier' => $this->contact?->identifier ?? $this->message->from,
            'contact_name'       => $this->contact?->name 
                                    ?? $this->rawPayload['contact_name'] 
                                    ?? null,
            'message_id'         => $this->message->message_id,
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
    // private function getIntegrationUidFromWorkflow(): string
    // {
    //     // TODO: Implement workflow integration UID retrieval
    //     // Example: $this->message->channel->workflow->integration_uid
    //     // Or: $this->message->channel->getMeta('workflow_integration_uid')
        
    //     return '01KESDXYRE3YP749MMQRNF93CM';
        
    // }
    
    /**
     * @todo
     * For now we send woocommerce integration uid,
     * but in future we have to send gautams chatbot integration uid  
     */
    private function getIntegrationUidFromWorkflow(): string
    {
        $context = [
            'message_id' => $this->message->id,
            'channel_id' => $this->message->channel_id ?? null,
        ];

        try {
            Log::debug('Resolving integration UID from workflow', $context);

            $channel = $this->message->channel;

            if (!$channel) {
                Log::warning('Channel not found for message', $context);
                return '';
            }

            Log::debug('Channel resolved', $context + [
                'channel_id' => $channel->id
            ]);

            $organisation = $channel->organisations()->first();

            if (!$organisation) {
                Log::warning('No organisation linked to channel', $context + [
                    'channel_id' => $channel->id
                ]);
                return '';
            }

            Log::debug('Organisation resolved for channel', $context + [
                'organisation_id' => $organisation->id,
                'organisation_uid' => $organisation->uid ?? null
            ]);

            // 🔹 Fetch integrations (FIX: call get() before load)
            $integrations = $organisation
                ->models(Integration::class)
                ->get()
                ->load(['supportedIntegration', 'metas']);

            Log::debug('Integrations fetched for organisation', $context + [
                'organisation_id' => $organisation->id,
                'count' => $integrations->count(),
            ]);

            // 🔹 Filter WooCommerce + status active
            $integration = $integrations->first(function ($integration) {

                $isWoo = optional($integration->supportedIntegration)->name === 'woocommerce';

                $isActive = strtolower($integration->status ?? '') === 'active';

                return $isWoo && $isActive;
            });


            if (!$integration) {
                Log::warning('No active WooCommerce integration found', $context + [
                    'organisation_id' => $organisation->id,
                    'available_integrations' => $integrations->map(fn ($i) => [
                        'id' => $i->id,
                        'uid' => $i->uid,
                        'supported' => optional($i->supportedIntegration)->name,
                        'active' => $i->getMeta('is_active'),
                    ]),
                ]);
                return '';
            }

            Log::info('Integration UID resolved successfully', $context + [
                'organisation_id' => $organisation->id,
                'integration_id' => $integration->id,
                'integration_uid' => $integration->uid,
                'supported' => optional($integration->supportedIntegration)->name,
            ]);

            return $integration->uid;

        } catch (\Throwable $e) {
            Log::error('Integration UID resolution failed', $context + [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1200),
            ]);

            return '';
        }
    }
    
    private function getCompanyId(): string
    {
        $displayPhone =
            $this->rawPayload['entry'][0]['changes'][0]['value']['metadata']['display_phone_number']
            ?? null;

        Log::debug('Resolved display_phone_number', [
            'display_phone_number' => $displayPhone
        ]);

        if (!$displayPhone) {
            Log::warning('display_phone_number not found in rawPayload');
            return '456789'; // safe default
        }

        // Normalize phone number (remove +, spaces, brackets, dashes)
        $normalized = preg_replace('/\D+/', '', $displayPhone);

        return match ($normalized) {
            '918777640062' => '456789',
            '19169907791'  => '123456',
            default        => '456789',
        };
    }
}