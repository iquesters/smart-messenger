<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

class ForwardToAgentJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $rawPayload;
    protected $contact;

    /**
     * Agent numbers list
     */
    protected array $agentNumbers = [
        '9749594771', '9163706222', '9804141094'
    ];

    protected function initialize(...$arguments): void
    {
        [
            $this->inboundMessage,
            $this->rawPayload,
            $this->contact
        ] = $arguments;
    }

    /**
     * Forward inbound message to agents
     */
    public function process(): void
    {
        try {
            $channel = $this->inboundMessage->channel;

            if (!$channel) {
                Log::warning('ForwardToAgentJob: channel missing, aborting', [
                    'message_id' => $this->inboundMessage->id
                ]);
                return;
            }

            Log::info('ForwardToAgentJob started', [
                'message_id' => $this->inboundMessage->id,
                'from_user'  => $this->inboundMessage->from,
                'type'       => $this->inboundMessage->message_type,
                'agents'     => $this->agentNumbers
            ]);

            foreach ($this->agentNumbers as $agentNumber) {

                Log::debug('Preparing payload for agent', [
                    'message_id' => $this->inboundMessage->id,
                    'agent'      => $agentNumber
                ]);

                $payload = $this->buildForwardPayload();

                if (!$payload) {
                    Log::warning('Payload build failed, skipping agent', [
                        'message_id' => $this->inboundMessage->id,
                        'agent'      => $agentNumber
                    ]);
                    continue;
                }

                // Add context prefix for text messages
                if ($payload['type'] === 'text') {
                    $payload['text'] =
                        "📩 Forwarded from {$this->inboundMessage->from}:\n\n" .
                        $payload['text'];
                }

                // Override recipient
                $payload['to_override'] = $agentNumber;

                Log::info('Dispatching SendWhatsAppReplyJob to agent', [
                    'message_id' => $this->inboundMessage->id,
                    'agent'      => $agentNumber,
                    'payload_type' => $payload['type']
                ]);

                SendWhatsAppReplyJob::dispatch(
                    $this->inboundMessage,
                    $payload
                );
            }

            Log::info('ForwardToAgentJob completed', [
                'message_id' => $this->inboundMessage->id
            ]);

        } catch (\Throwable $e) {
            Log::error('ForwardToAgentJob failed', [
                'message_id' => $this->inboundMessage->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Build WhatsApp payload from inbound message
     */
    protected function buildForwardPayload(): ?array
    {
        $type = $this->inboundMessage->message_type;

        Log::debug('Building forward payload', [
            'message_id' => $this->inboundMessage->id,
            'type'       => $type
        ]);

        // TEXT MESSAGE
        if ($type === 'text') {
            return [
                'type' => 'text',
                'text' => $this->inboundMessage->content,
            ];
        }

        // IMAGE MESSAGE
        if ($type === 'image') {

            $mediaPath = $this->inboundMessage->getMeta('media_path');
            $mediaUrl  = $this->inboundMessage->getMeta('media_url');
            $mimeType  = $this->inboundMessage->getMeta('media_mime_type');
            $size      = $this->inboundMessage->getMeta('media_size');

            if (!$mediaPath || !$mimeType) {
                Log::warning('ForwardToAgentJob: missing stored media meta', [
                    'message_id' => $this->inboundMessage->id,
                    'path' => $mediaPath,
                    'mime' => $mimeType
                ]);
                return null;
            }

            // Extract original caption if stored in content JSON
            $originalCaption = null;

            $content = json_decode($this->inboundMessage->content, true);
            if (is_array($content) && !empty($content['caption'])) {
                $originalCaption = $content['caption'];
            }

            // Build final caption
            $finalCaption = "📩 Forwarded from {$this->inboundMessage->from}";
            if ($originalCaption) {
                $finalCaption .= "\n\n" . $originalCaption;
            }

            return [
                'type' => 'image',
                'caption' => $finalCaption,
                'stored_media' => [
                    'driver'    => 'local',
                    'path'      => $mediaPath,
                    'url'       => $mediaUrl,
                    'mime_type' => $mimeType,
                    'size'      => $size,
                ],
            ];

        }

        // Fallback
        Log::warning('Unknown message type, using fallback text', [
            'message_id' => $this->inboundMessage->id,
            'type'       => $type
        ]);

        return [
            'type' => 'text',
            'text' => 'Forwarded message received',
        ];
    }
}