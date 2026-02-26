<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\AgentResolverService;

class ForwardToAgentJob extends BaseJob
{
    use Loggable;

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

            $agentData = app(AgentResolverService::class)->resolvePhones($channel);
            $agentNumbers = $agentData['active'] ?? [];

            if (empty($agentNumbers)) {
                $this->logWarning('No active agents found' . $this->ctx([
                    'message_id' => $this->inboundMessage->id,
                    'contact_id' => $this->contact?->id,
                ]));
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

    protected function buildHandoverText(): string
    {
        $summary = $this->handoverContext['summary'] ?? [];
        $turns = $summary['turns'] ?? [];
        $aiSummary = $summary['ai_summary'] ?? [];
        $contactName = $this->contact?->name ?: 'Unknown Contact';
        $identifier = $this->inboundMessage->from;
        $chatbotName = $this->resolveChatbotName();
        $recentMessages = $this->buildRecentMessagesLines();

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

        if (!empty($recentMessages)) {
            foreach ($recentMessages as $line) {
                $lines[] = $line;
            }
        } elseif (!empty($turns)) {
            foreach ($turns as $turn) {
                $lines[] = "_{$contactName}_ : " . ($turn['user_message'] ?? '');
                $lines[] = "_{$chatbotName}_ : " . ($turn['chatbot_answer'] ?? '');
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
            'recent_messages_count' => count($recentMessages),
            'chatbot_name' => $chatbotName,
            'contact_name' => $contactName,
            'text_length' => strlen($message),
        ]));

        return $message;
    }

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

    protected function buildRecentMessagesLines(int $limit = 8): array
    {
        $identifier = $this->inboundMessage->from;
        $contactName = $this->contact?->name ?: 'Customer';

        $messages = Message::query()
            ->where('channel_id', $this->inboundMessage->channel_id)
            ->where(function ($query) use ($identifier) {
                $query->where('from', $identifier)
                    ->orWhere(function ($inner) use ($identifier) {
                        $inner->where('to', $identifier)
                            ->whereNotNull('integration_id');
                    });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $this->logInfo('Fetched recent messages for handover preview' . $this->ctx([
            'message_id' => $this->inboundMessage->id,
            'identifier' => $identifier,
            'requested_limit' => $limit,
            'fetched_count' => $messages->count(),
        ]));

        $lines = [];
        foreach ($messages as $message) {
            $senderName = $message->from === $identifier
                ? $contactName
                : ($message->sender_name ?: 'Chatbot');

            $text = $message->message_type === 'text'
                ? (string) $message->content
                : '[' . $message->message_type . ' message]';

            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text === '') {
                continue;
            }

            $lines[] = "_{$senderName}_ : {$text}";
        }

        return $lines;
    }

    private function ctx(array $context): string
    {
        return ' | context=' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
