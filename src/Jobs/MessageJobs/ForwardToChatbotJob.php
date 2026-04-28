<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\Integration\Models\Integration;
use Iquesters\Integration\Models\SupportedIntegration;
use Iquesters\SmartMessenger\Constants\Constants;
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
        $this->logMethodStart($this->ctx([
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
        ]));

        try {
            $this->logInfo('Forwarding message to chatbot API' . $this->ctx([
                'message_id' => $this->message->id,
                'from' => $this->message->from,
                'type' => $this->message->message_type
            ]));

            // Prepare payload for chatbot
            $payload = $this->preparePayload();
            $this->logDebug('Calling chatbot API with payload' . $this->ctx([
                'message_id' => $this->message->id,
                'payload' => $payload,
            ]));

            $request = Http::timeout(160) // 2m40s max API wait
                ->withOptions([
                    'connect_timeout' => 10,
                    'read_timeout' => 160,
                ]);

            $apiToken = $this->getChatbotApiToken();
            if ($apiToken) {
                $request->withToken($apiToken);
            } else {
                $this->logWarning('Chatbot API token not found; sending request without bearer token' . $this->ctx([
                    'message_id' => $this->message->id,
                ]));
            }

            $response = $request->post($this->getChatbotApiUrl(), $payload);
    
            $this->logInfo('Chatbot API response received' . $this->ctx([
                'message_id' => $this->message->id,
                'status' => $response->status(),
                'response' => $response->json()
            ]));

            if (!$response->successful()) {
                $this->logError('Chatbot API call failed' . $this->ctx([
                    'message_id' => $this->message->id,
                    'status' => $response->status(),
                    'response' => $response->json()
                ]));
                return;
            }

            $this->logInfo('Chatbot API accepted request; outbound delivery is handled asynchronously by chatbot v3 flow' . $this->ctx([
                'message_id' => $this->message->id,
            ]));

        } catch (\Throwable $e) {
            $this->logError('ForwardToChatbotJob failed' . $this->ctx([
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'message_id' => $this->message->id,
            ]));
        }
    }

    /**
     * Prepare simplified payload for chatbot
     */
    private function preparePayload(): array
    {
        $integrationUid = $this->getIntegrationUidFromWorkflow();

        $payload = [
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

        $this->logInfo('Prepared chatbot payload' . $this->ctx([
            'integration_uid' => $integrationUid,
            'contact_uid' => $this->contact?->uid,
            'contact_identifier' => $payload['contact_identifier'],
            'has_message' => isset($payload['message']),
            'has_file' => isset($payload['file_url']),
            'message_type' => $this->message->message_type
        ]));

        return $payload;
    }

    /**
     * Resolve the active Gautams Chatbot integration UID for the message context.
     */
    private function getIntegrationUidFromWorkflow(): string
    {
        $context = [
            'message_id' => $this->message->id,
            'channel_id' => $this->message->channel_id ?? null,
        ];

        try {
            $this->logDebug('Resolving integration UID from workflow' . $this->ctx($context));

            $channel = $this->message->channel;

            if (!$channel) {
                $this->logWarning('Channel not found for message' . $this->ctx($context));
                return '';
            }

            $this->logDebug('Channel resolved' . $this->ctx($context + [
                'channel_id' => $channel->id
            ]));

            $organisation = $channel->organisations()->first();

            if (!$organisation) {
                $this->logWarning('No organisation linked to channel' . $this->ctx($context + [
                    'channel_id' => $channel->id
                ]));
                return '';
            }

            $this->logDebug('Organisation resolved for channel' . $this->ctx($context + [
                'organisation_id' => $organisation->id,
                'organisation_uid' => $organisation->uid ?? null
            ]));

            // 🔹 Fetch integrations (FIX: call get() before load)
            $integrations = $organisation
                ->models(Integration::class)
                ->get()
                ->load(['supportedIntegration', 'metas']);

            $this->logDebug('Integrations fetched for organisation' . $this->ctx($context + [
                'organisation_id' => $organisation->id,
                'count' => $integrations->count(),
            ]));

            // 🔹 Filter WooCommerce + status active
            $integration = $integrations->first(function ($integration) {

                $isChatbot = optional($integration->supportedIntegration)->name === Constants::GAUTAMS_CHATBOT;

                $isActive = strtolower($integration->status ?? '') === Constants::ACTIVE;

                return $isChatbot && $isActive;
            });


            if (!$integration) {
                $this->logWarning('No active Gautams Chatbot integration found' . $this->ctx($context + [
                    'organisation_id' => $organisation->id,
                    'available_integrations' => $integrations->map(fn ($i) => [
                        'id' => $i->id,
                        'uid' => $i->uid,
                        'supported' => optional($i->supportedIntegration)->name,
                        'active' => $i->getMeta('is_active'),
                    ]),
                ]));
                return '';
            }

            $this->logInfo('Integration UID resolved successfully' . $this->ctx($context + [
                'organisation_id' => $organisation->id,
                'integration_id' => $integration->id,
                'integration_uid' => $integration->uid,
                'supported' => optional($integration->supportedIntegration)->name,
            ]));

            return $integration->uid;

        } catch (\Throwable $e) {
            $this->logError('Integration UID resolution failed' . $this->ctx($context + [
                'error' => $e->getMessage(),
            ]));

            return '';
        }
    }
    
    private function getChatbotApiToken(): ?string
    {
        return $this->resolveSupportedChatbotIntegration()?->getMeta(Constants::CHATBOT_API_TOKEN);
    }

    private function getChatbotApiUrl(): string
    {
        $apiUrl = $this->resolveSupportedChatbotIntegration()?->getMeta(Constants::CHATBOT_API_URL);

        if ($apiUrl) {
            return $apiUrl;
        }

        $this->logWarning('Chatbot API URL not found in supported integration meta' . $this->ctx([
            'message_id' => $this->message->id,
            'supported_integration_name' => Constants::GAUTAMS_CHATBOT,
        ]));

        return '';
    }

    private function resolveSupportedChatbotIntegration(): ?SupportedIntegration
    {
        try {
            $supportedIntegration = SupportedIntegration::query()
                ->with('metas')
                ->where('name', Constants::GAUTAMS_CHATBOT)
                ->first();

            if (!$supportedIntegration) {
                $this->logWarning('Supported integration not found for chatbot token' . $this->ctx([
                    'message_id' => $this->message->id,
                    'supported_integration_name' => Constants::GAUTAMS_CHATBOT,
                ]));
                return null;
            }

            return $supportedIntegration;
        } catch (\Throwable $e) {
            $this->logError('Failed resolving supported chatbot integration' . $this->ctx([
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]));

            return null;
        }
    }

}