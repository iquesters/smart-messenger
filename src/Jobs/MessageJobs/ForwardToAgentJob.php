<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Helpers\DateTimeHelper;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\AgentResolverService;

class ForwardToAgentJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $rawPayload;
    protected $contact;
    protected ?array $handoverContext = null;

    protected function initialize(...$arguments): void
    {
        $this->inboundMessage = $arguments[0];
        $this->rawPayload = $arguments[1] ?? [];
        $this->contact = $arguments[2] ?? null;
        $this->handoverContext = $arguments[3] ?? null;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'message_id' => $this->inboundMessage->id,
            'handover_action_id' => $this->handoverContext['action_id'] ?? null,
        ]));

        try {
            $channel = $this->inboundMessage->channel;
            if (!$channel) {
                $this->logWarning('Channel missing, aborting forward-to-agent' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                ]));
                return;
            }

            $agentResolver = app(AgentResolverService::class);
            $agentData = $agentResolver->resolvePhones($channel);
            $agentNumbers = $agentData['active'] ?? [];

            if (empty($agentNumbers)) {
                $integration = $agentResolver->resolveActiveIntegrationFromChannel($channel);

                $this->logWarning('No active agents found' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'contact_id' => $this->contact?->id,
                    'integration_uid' => $integration?->uid,
                ]));

                $this->notifyTelegramForNoActiveAgent($integration);
                return;
            }

            $this->logInfo('ForwardToAgentJob started' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'from_user' => $this->inboundMessage->from,
                'message_type' => $this->inboundMessage->message_type,
                'agents_count' => count($agentNumbers),
                'is_handover' => !empty($this->handoverContext),
                'handover_action_id' => $this->handoverContext['action_id'] ?? null,
            ]));

            foreach ($agentNumbers as $agentNumber) {
                $this->logDebug('Preparing payload for agent' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'agent' => $agentNumber,
                ]));

                $payload = $this->buildForwardPayload();

                if (!$payload) {
                    $this->logWarning('Payload build failed, skipping agent' . $this->ctx([
                        'message_id' => $this->inboundMessage->id,
                        'agent' => $agentNumber,
                    ]));
                    continue;
                }

                if ($payload['type'] === 'text' && empty($this->handoverContext)) {
                    $contactName = $this->contact?->name ?: 'Unknown Contact';
                    $payload['text'] =
                        "Forwarded from {$this->inboundMessage->from} | {$contactName}:\n\n" .
                        $payload['text'];
                }

                $payload['to_override'] = $agentNumber;
                $payload['_forwarded_from'] = $this->inboundMessage->id;

                $this->logInfo('Dispatching SendWhatsAppReplyJob to agent' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'agent' => $agentNumber,
                    'payload_type' => $payload['type'] ?? null,
                ]));

                SendWhatsAppReplyJob::dispatch($this->inboundMessage, $payload);
            }

            $this->logInfo('ForwardToAgentJob completed' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'agents_count' => count($agentNumbers),
            ]));
        } catch (\Throwable $e) {
            $this->logError('ForwardToAgentJob failed' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'error' => $e->getMessage(),
            ]));
            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'message_id' => $this->inboundMessage->id,
                'handover_action_id' => $this->handoverContext['action_id'] ?? null,
            ]));
        }
    }

    protected function buildForwardPayload(): ?array
    {
        if (!empty($this->handoverContext)) {
            $this->logInfo('Building handover payload for active agent' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'action_id' => $this->handoverContext['action_id'] ?? null,
                'queue_name' => $this->handoverContext['queue_name'] ?? null,
            ]));

            return [
                'type' => 'text',
                'text' => $this->buildHandoverText(),
            ];
        }

        $type = $this->inboundMessage->message_type;
        $this->logDebug('Building regular forward payload' . $this->ctx([
            'message_id' => $this->inboundMessage->id,
            'message_type' => $type,
        ]));

        if ($type === 'text') {
            return [
                'type' => 'text',
                'text' => $this->inboundMessage->content,
            ];
        }

        if ($type === 'image') {
            $mediaPath = $this->inboundMessage->getMeta('media_path');
            $mediaUrl = $this->inboundMessage->getMeta('media_url');
            $mimeType = $this->inboundMessage->getMeta('media_mime_type');
            $size = $this->inboundMessage->getMeta('media_size');

            if (!$mediaPath || !$mimeType) {
                $this->logWarning('Missing stored media meta for image forward' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'has_path' => !empty($mediaPath),
                    'has_mime' => !empty($mimeType),
                ]));
                return null;
            }

            $originalCaption = null;
            $content = json_decode($this->inboundMessage->content, true);
            if (is_array($content) && !empty($content['caption'])) {
                $originalCaption = $content['caption'];
            }

            $finalCaption = "Forwarded from {$this->inboundMessage->from}";
            if ($originalCaption) {
                $finalCaption .= "\n\n" . $originalCaption;
            }

            return [
                'type' => 'image',
                'caption' => $finalCaption,
                'stored_media' => [
                    'driver' => 'local',
                    'path' => $mediaPath,
                    'url' => $mediaUrl,
                    'mime_type' => $mimeType,
                    'size' => $size,
                ],
            ];
        }

        $this->logWarning('Unknown message type, using fallback text' . $this->ctx([
            'message_id' => $this->inboundMessage->id,
            'message_type' => $type,
        ]));

        return [
            'type' => 'text',
            'text' => 'Forwarded message received',
        ];
    }

    // Build the agent handover text strictly from the chatbot-provided summary payload.
    protected function buildHandoverText(): string
    {
        $summary = $this->handoverContext['summary'] ?? [];
        $turns = $summary['turns'] ?? [];
        $aiSummary = $summary['ai_summary'] ?? [];
        $contactName = $this->contact?->name ?: 'Unknown Contact';
        $identifier = $this->inboundMessage->from;
        $chatbotName = $this->resolveChatbotName();

        $lines = [
            '*Chatbot handover requested.*',
            '',
            '*Handover Reason*: ' . ($aiSummary['handover_trigger'] ?? 'N/A'),
            '',
            '*Suggested Action*: ' . ($aiSummary['agent_next_step'] ?? 'N/A'),
            '',
            "Contact: {$identifier} | {$contactName}",
            '',
            '*Last Few Messages*',
        ];

        // Customer block intentionally kept commented for future use:
        // *Customer*
        // _Source_: Integration Name (woo for now)
        // _Identifier_: xy123
        // _Email_:
        // _Phone_:

        if (!empty($turns)) {
            foreach ($turns as $turn) {
                $userMessage = trim((string) ($turn['user_message'] ?? ''));
                $chatbotAnswer = trim((string) ($turn['chatbot_answer'] ?? ''));
                $userTimestamp = $this->formatMessageTimestamp($turn['user_message_ts_utc'] ?? null);
                $chatbotTimestamp = $this->formatMessageTimestamp($turn['chatbot_answer_ts_utc'] ?? null);

                if ($userMessage !== '') {
                    $lines[] = $this->formatRecentMessageLine($contactName, $userMessage, $userTimestamp);
                }

                if ($chatbotAnswer !== '') {
                    $lines[] = $this->formatRecentMessageLine($chatbotName, $chatbotAnswer, $chatbotTimestamp);
                }
            }
        } else {
            $lines[] = '_No messages available_';
        }

        $lines[] = '';
        $lines[] = '*Additional Info*';
        $lines[] = "_Forwarded from {$chatbotName}_";
        $lines[] = '_Full conversation_ : ' . ($aiSummary['full_conversation'] ?? 'N/A');
        $lines[] = '';
        $lines[] = '*Dev Info*';
        $lines[] = '_Action ID_: ' . ($this->handoverContext['action_id'] ?? 'N/A');
        $lines[] = '_Queue_: ' . ($this->handoverContext['queue_name'] ?? 'N/A');

        $message = implode("\n", $lines);

        $this->logInfo('Handover text built for agent dispatch' . $this->ctx([
            'message_id' => $this->inboundMessage->id,
            'action_id' => $this->handoverContext['action_id'] ?? null,
            'turns_count' => count($turns),
            'chatbot_name' => $chatbotName,
            'contact_name' => $contactName,
            'text_length' => strlen($message),
        ]));

        return $message;
    }

    // Resolve the latest chatbot display name for the contact so handover messages stay readable.
    protected function resolveChatbotName(): string
    {
        $identifier = $this->inboundMessage->from;

        $chatbotMessage = Message::query()
            ->where('channel_id', $this->inboundMessage->channel_id)
            ->where('to', $identifier)
            ->whereNotNull('integration_id')
            ->orderByDesc('id')
            ->first();

        $name = $chatbotMessage?->sender_name ?: 'Chatbot';

        $this->logInfo('Resolved chatbot sender name for handover' . $this->ctx([
            'message_id' => $this->inboundMessage->id,
            'resolved_name' => $name,
            'chatbot_message_id' => $chatbotMessage?->id,
        ]));

        return $name;
    }

    // @todo Move recent-message formatting into a shared helper once this is reused elsewhere.
    protected function formatRecentMessageLine(string $senderName, string $text, ?string $timestamp = null): string
    {
        $prefix = $timestamp ? "[{$timestamp}] " : '';

        return $prefix . "_{$senderName}_ : {$text}";
    }

    // @todo Move timestamp formatting into a shared helper once handover formatting is standardized.
    protected function formatMessageTimestamp($timestamp): ?string
    {
        if (empty($timestamp)) {
            return null;
        }

        $formatted = DateTimeHelper::displayDateTime($timestamp, '');

        return $formatted !== '' ? $formatted : null;
    }

    protected function notifyTelegramForNoActiveAgent($integration): void
    {
        // Temporary fallback: remove this once no-agent handling is implemented properly.
        if (!$integration) {
            $this->logWarning('Telegram fallback skipped because integration was not resolved' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
            ]));
            return;
        }

        $chatId = $integration->getMeta('telegram_chat_id');

        if (empty($chatId)) {
            $this->logWarning('Telegram fallback skipped because telegram_chat_id meta is missing' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'integration_uid' => $integration->uid,
            ]));
            return;
        }

        $messageBody = !empty($this->handoverContext)
            ? $this->buildHandoverText()
            : $this->buildTelegramFallbackMessage();
        $message = "No active agent found.\n\n{$messageBody}";

        try {
            $response = Http::acceptJson()->post(
                'https://api-util.iquesters.com/telegram/send?chat_id=' .
                urlencode((string) $chatId) .
                '&message=' .
                urlencode($message),
                []
            );

            if (!$response->successful()) {
                $this->logWarning('Telegram fallback call failed' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'integration_uid' => $integration->uid,
                    'chat_id' => $chatId,
                    'response_status' => $response->status(),
                ]));
                return;
            }

            $this->logInfo('Telegram fallback sent for no-active-agent case' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'integration_uid' => $integration->uid,
                'chat_id' => $chatId,
            ]));
        } catch (\Throwable $e) {
            $this->logError('Telegram fallback failed' . $this->ctx([
                'message_id' => $this->inboundMessage->id,
                'integration_uid' => $integration->uid,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]));
        }
    }

    protected function buildTelegramFallbackMessage(): string
    {
        $contactName = $this->contact?->name ?: 'Unknown Contact';
        $payload = $this->buildForwardPayload();
        $text = $payload['text'] ?? 'Forwarded message received';

        return sprintf(
            "Contact: %s | %s\n\n%s",
            $this->inboundMessage->from,
            $contactName,
            $text
        );
    }

}