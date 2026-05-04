<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ChatSessionLookupService;
use Iquesters\SmartMessenger\Services\HumanHandoverStateResolver;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;

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
            'whatsapp_message_id' => $this->message->message_id,
            'message_type' => $this->message->message_type,
        ]));

        try {
            $chatbotResolver = $this->chatbotResolver();
            $chatbotIntegrationUid = $chatbotResolver->resolveUidFromMessage($this->message);

            $this->logInfo('Forwarding message to chatbot API' . $this->ctx([
                'message_id' => $this->message->id,
                'whatsapp_message_id' => $this->message->message_id,
                'from' => $this->message->from,
                'type' => $this->message->message_type,
                'contact_uid' => $this->contact?->uid,
                'chatbot_integration_uid' => $chatbotIntegrationUid,
            ]));

            if ($this->routeToHumanAgentIfHandoverActive($chatbotIntegrationUid)) {
                return;
            }

            // Prepare payload for chatbot
            $payload = $this->preparePayload($chatbotIntegrationUid);
            $this->logDebug('Calling chatbot API with payload' . $this->ctx([
                'message_id' => $this->message->id,
                'payload' => $payload,
                'route_decision' => 'chatbot_core',
            ]));

            $request = Http::timeout(160) // 2m40s max API wait
                ->withOptions([
                    'connect_timeout' => 10,
                    'read_timeout' => 160,
                ]);

            $apiToken = $chatbotResolver->getApiToken();
            if ($apiToken) {
                $request->withToken($apiToken);
            } else {
                $this->logWarning('Chatbot API token not found; sending request without bearer token' . $this->ctx([
                    'message_id' => $this->message->id,
                ]));
            }

            $response = $request->post($chatbotResolver->getApiUrl(), $payload);
    
            $this->logInfo('Chatbot API response received' . $this->ctx([
                'message_id' => $this->message->id,
                'whatsapp_message_id' => $this->message->message_id,
                'status' => $response->status(),
                'response' => $response->json(),
                'route_decision' => 'chatbot_core',
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
                'route_decision' => 'chatbot_core',
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
    private function preparePayload(string $integrationUid): array
    {
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

    private function routeToHumanAgentIfHandoverActive(string $chatbotIntegrationUid): bool
    {
        $context = [
            'message_id' => $this->message->id,
            'whatsapp_message_id' => $this->message->message_id,
            'contact_uid' => $this->contact?->uid,
            'chatbot_integration_uid' => $chatbotIntegrationUid,
            'job_class' => static::class,
        ];

        $this->logInfo('Starting session-based handover check before chatbot-core call' . $this->ctx($context));

        $chatSession = app(ChatSessionLookupService::class)->findLatestActive(
            $this->contact?->uid,
            $chatbotIntegrationUid
        );

        if (!$chatSession) {
            $this->logInfo('Session lookup returned no active chat session; continuing to chatbot-core' . $this->ctx($context + [
                'route_decision' => 'chatbot_core',
            ]));
            return false;
        }

        $handoverState = app(HumanHandoverStateResolver::class)->resolve($chatSession->context_json);

        $this->logInfo('Session handover state parsed' . $this->ctx($context + [
            'chat_session_id' => $chatSession->session_id,
            'human_handover_active' => $handoverState['active'],
            'hand_over_time' => $handoverState['hand_over_time'],
            'handover_reason' => $handoverState['reason'],
            'ended_utc' => $handoverState['ended_utc'],
            'raw_path' => $handoverState['raw_path'],
        ]));

        if (!$handoverState['active']) {
            $this->logInfo('Latest session state is not in active human handover; continuing to chatbot-core' . $this->ctx($context + [
                'chat_session_id' => $chatSession->session_id,
                'route_decision' => 'chatbot_core',
            ]));
            return false;
        }

        $this->logInfo('Active human handover detected from session state' . $this->ctx($context + [
            'chat_session_id' => $chatSession->session_id,
            'human_handover_active' => true,
            'hand_over_time' => $handoverState['hand_over_time'],
            'handover_reason' => $handoverState['reason'],
            'route_decision' => 'human_agent',
        ]));

        if (
            $this->message->getMeta('human_route_decision') === 'human_agent' &&
            $this->message->getMeta('chat_session_id') === $chatSession->session_id
        ) {
            $this->logInfo('Human routing already recorded for this inbound message; skipping duplicate agent dispatch' . $this->ctx($context + [
                'chat_session_id' => $chatSession->session_id,
                'route_decision' => 'human_agent',
            ]));

            return true;
        }

        $this->markMessageForHumanHandling($chatSession->session_id, $handoverState);

        ForwardToAgentJob::dispatch(
            $this->message,
            $this->rawPayload,
            $this->contact
        );

        $this->logInfo('Chatbot-core call skipped; ForwardToAgentJob dispatched for human routing' . $this->ctx($context + [
            'chat_session_id' => $chatSession->session_id,
            'route_decision' => 'human_agent',
        ]));

        return true;
    }

    private function markMessageForHumanHandling(string $chatSessionId, array $handoverState): void
    {
        $metaValues = [
            'human_pending' => '1',
            'human_route_decision' => 'human_agent',
            'human_handover_source' => 'session_state',
            'chat_session_id' => $chatSessionId,
            'human_handover_time' => $handoverState['hand_over_time'] ?? '',
            'human_handover_reason' => $handoverState['reason'] ?? '',
        ];

        foreach ($metaValues as $key => $value) {
            $this->message->setMeta($key, (string) $value);
        }

        $this->logInfo('Human routing message metas written' . $this->ctx([
            'message_id' => $this->message->id,
            'whatsapp_message_id' => $this->message->message_id,
            'contact_uid' => $this->contact?->uid,
            'chat_session_id' => $chatSessionId,
            'human_handover_active' => $handoverState['active'] ?? false,
            'hand_over_time' => $handoverState['hand_over_time'] ?? null,
            'handover_reason' => $handoverState['reason'] ?? null,
            'route_decision' => 'human_agent',
        ]));
    }

    private function chatbotResolver(): ChatbotIntegrationResolverService
    {
        return app(ChatbotIntegrationResolverService::class);
    }

}
