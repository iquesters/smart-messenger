<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Http;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Services\AgentResolverService;

class SendWhatsAppReplyJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $payload;

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->payload] = $arguments;
    }

    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'inbound_message_id' => $this->inboundMessage->id,
            'payload_type' => $this->payload['type'] ?? null,
        ]));

        $channel = $this->inboundMessage->channel;
        $to = $this->payload['to_override'] ?? $this->inboundMessage->from;

        if (!$channel || !$to) {
            $error = 'Missing channel or recipient for WhatsApp reply';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'has_channel' => !empty($channel),
                'to' => $to,
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
        $token = $channel->getMeta('system_user_token');

        if ((!$phoneNumberId || !$token) && $channel) {
            $resolvedChannel = app(AgentResolverService::class)
                ->resolveWhatsAppChannelForAgentRouting($channel);

            if ($resolvedChannel->id !== $channel->id) {
                $channel = $resolvedChannel;
                $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
                $token = $channel->getMeta('system_user_token');
            }
        }

        if (!$phoneNumberId || !$token) {
            $error = 'Missing WhatsApp channel credentials';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'has_phone_number_id' => !empty($phoneNumberId),
                'has_token' => !empty($token),
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        $requestPayload = $this->buildWhatsAppPayload($to);
        if (!$requestPayload) {
            $error = 'WhatsApp request payload could not be built';
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'payload_type' => $this->payload['type'] ?? null,
                'payload_keys' => array_keys($this->payload),
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context);
            return;
        }

        // The last point at which an AI reply can still be stopped (F2).
        // CCX decided to answer this turn seconds ago; an operator may have
        // taken the conversation over since. Sending now would put a bot
        // reply after a human said "I'll take this from here".
        //
        // Checked here rather than only in CCX because this is the final
        // instruction to Meta: everything before it is a decision, and this
        // is the action.
        if (($this->payload['_actor'] ?? null) === 'ai'
            && $this->humanHasTakenOver($this->inboundMessage)) {
            $this->logWarning('AI reply suppressed: human handover active' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return;
        }

        $response = Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            $requestPayload
        );

        if (!$response->successful()) {
            $error = 'WhatsApp API send failed';
            $errorDetail = $response->json();
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'http_status' => $response->status(),
                'response' => $errorDetail,
            ];
            $this->logError($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context, $errorDetail);
            return;
        }

        $waMessageId = $response->json('messages.0.id');
        if (!$waMessageId) {
            $error = 'WhatsApp send succeeded but no message_id returned';
            $errorDetail = $response->json();
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $errorDetail,
            ];
            $this->logWarning($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context, $errorDetail);
            return;
        }

        $messageType = $this->payload['type'];
        
        $this->logInfo('Saving message with integration' . $this->ctx([
            'integration_id' => $this->payload['integration_id']
                ?? $this->inboundMessage->integration_id
        ]));
        
        $outbound = Message::create([
            'channel_id'   => $channel->id,
            'integration_id' => $this->payload['integration_id']
                        ?? $this->inboundMessage->integration_id,
            'message_id'   => $waMessageId,
            'from'         => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to'           => $to,
            'message_type' => $messageType,
            'content'      => $this->buildContentForDatabase($messageType),
            'timestamp'    => now(),
            'status'       => Constants::SENT,
            'raw_payload'  => $response->json(),
            'created_by'   => $this->payload['created_by_override']
                  ?? $this->inboundMessage->created_by,
        ]);

        /**
         * Classification metas for the Operator Inbox projector (§8.2).
         *
         * `_actor` is the existing operator/suppression key. The AI producer
         * uses `_inbox_actor` only for persisted attribution, leaving that
         * pre-send gate unchanged. A reply anchor prevents a delayed answer
         * from crediting a newer customer question.
         */
        foreach ([
            '_actor' => 'actor',
            // Metadata-only AI classification; unlike `_actor`, this key
            // does not activate the separate pre-send takeover guard.
            '_inbox_actor' => 'actor',
            '_ai_kind' => 'ai_kind',
            '_inbox_reply_to_message_id' => 'reply_to_message_id',
            '_operator_command_uid' => 'operator_command_uid',
            '_operator_anchor_inbound_message_id' => 'operator_anchor_inbound_message_id',
            '_handover_trigger' => 'handover_trigger',
        ] as $payloadKey => $metaKey) {
            if (!empty($this->payload[$payloadKey])) {
                $outbound->setMeta($metaKey, (string) $this->payload[$payloadKey]);
            }
        }

        /**
         * Links the outbound row back to the command that produced it, so
         * the projector can move the command to `sent` and the client can
         * replace its pending bubble.
         */
        if (!empty($this->payload['_operator_command_uid'])) {
            \Illuminate\Support\Facades\DB::table('operator_outbound_commands')
                ->where('uid', $this->payload['_operator_command_uid'])
                ->where('state', 'dispatched')
                ->update([
                    'state' => 'sent',
                    'message_id' => $outbound->id,
                    'updated_at' => now(),
                ]);
        }

        /**
         * Optional linkage if message is forwarded
        */
        if (!empty($this->payload['_forwarded_from'])) {
            $outbound->setMeta('forwarded_from', $this->payload['_forwarded_from']);
        }

        /**
         * 🔥 STORE MEDIA META (NEW SYSTEM)
         */
        if (!empty($this->payload['stored_media'])) {
            $media = $this->payload['stored_media'];

            $outbound->setMeta('media_driver', $media['driver']);
            $outbound->setMeta('media_path', $media['path']);
            $outbound->setMeta('media_url', $media['url']);
            $outbound->setMeta('mime_type', $media['mime_type']);
            $outbound->setMeta('media_size', (string) $media['size']);

            if (!empty($this->payload['filename'])) {
                $outbound->setMeta('original_filename', $this->payload['filename']);
            }
        }
    }

    /**
     * Save a FAILED message so the UI shows the error instead of silently dropping it.
     */
    private function saveFailedMessage(
        ?Channel $channel,
        ?string $to,
        string $error,
        array $context = [],
        ?array $errorDetail = null,
    ): void {
        if (!$channel || !$to) {
            return;
        }

        $messageType = $this->payload['type'] ?? 'unknown';

        Message::create([
            'channel_id'    => $channel->id,
            'integration_id' => $this->payload['integration_id']
                        ?? $this->inboundMessage->integration_id,
            'from'          => ($channel->getMeta('country_code') ?? '') . $channel->getMeta('whatsapp_number'),
            'to'            => $to,
            'message_type'  => $messageType,
            'content'       => $this->buildContentForDatabase($messageType),
            'timestamp'     => now(),
            'status'        => Constants::FAILED,
            'raw_payload'   => [
                'error' => $error,
                'context' => $context,
                'error_detail' => $errorDetail,
                'failed_at' => now()->toIso8601String(),
            ],
            'created_by'    => $this->payload['created_by_override']
                  ?? $this->inboundMessage->created_by,
        ]);

        $this->logInfo('Saved FAILED message to UI' . $this->ctx([
            'error' => $error,
        ]));
    }

    /**
     * Whether a human owns this conversation right now.
     *
     * Reads the latest chat session for the contact and resolves its
     * handover state with the same resolver the handover service uses, so
     * this gate and the thing it is gating cannot disagree about what
     * "handed over" means.
     *
     * Fails **open** - an unreadable session does not suppress the reply.
     * That is deliberate: this is the third check, after CCX's orchestrator
     * short-circuit and its post-turn re-read under lock. Failing closed
     * here would silence the AI for every conversation the moment the
     * session table had a hiccup, which is a far larger outage than the
     * narrow race this covers.
     */
    private function humanHasTakenOver($inbound): bool
    {
        try {
            $contact = $inbound->from;
            if (!$contact) {
                return false;
            }

            $session = \Iquesters\SmartMessenger\Models\ChatSession::query()
                ->where('contact_uid', $contact)
                ->orderByDesc('last_active_at')
                ->first();

            if (!$session) {
                return false;
            }

            $resolver = app(\Iquesters\SmartMessenger\Services\HumanHandoverStateResolver::class);
            $state = $resolver->resolve($session->context_json);

            return (bool) ($state['active'] ?? false);
        } catch (\Throwable $e) {
            $this->logWarning('Could not check handover state before sending' . $this->ctx([
                'inbound_message_id' => $inbound->id ?? null,
                'error_type' => get_class($e),
            ]));
            return false;
        }
    }

    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return $this->payload['text'] ?? '';
        }

        if ($type === 'image') {
            return json_encode([
                'caption'   => $this->payload['caption'] ?? '',
                'image_url' => $this->payload['image_url']
                    ?? ($this->payload['stored_media']['url'] ?? null),
            ]);
        }

        if ($type === 'video') {
            return json_encode([
                'caption' => $this->payload['caption'] ?? '',
                'video_url' => $this->payload['stored_media']['url'] ?? null,
            ]);
        }

        if ($type === 'document') {
            return json_encode([
                'caption'      => $this->payload['caption'] ?? '',
                'filename'     => $this->payload['filename']
                    ?? ($this->payload['stored_media']['filename'] ?? null),
                'document_url' => $this->payload['stored_media']['url'] ?? null,
            ]);
        }

        return json_encode($this->payload);
    }

    private function buildWhatsAppPayload(string $to): ?array
    {
        if ($this->payload['type'] === 'text') {
            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $this->payload['text']]
            ];
        }

        if ($this->payload['type'] === 'image') {

            if (empty($this->payload['stored_media'])) {
                $this->logError('Image payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp(
                $this->payload['stored_media']
            );

            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'image',
                'image'=> [
                    'id'      => $mediaId,
                    'caption' => $this->payload['caption'] ?? '',
                ]
            ];
        }

        if ($this->payload['type'] === 'video') {

            if (empty($this->payload['stored_media'])) {
                $this->logError('Video payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp(
                $this->payload['stored_media']
            );

            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'video',
                'video'=> [
                    'id'      => $mediaId,
                    'caption' => $this->payload['caption'] ?? '',
                ]
            ];
        }

        if ($this->payload['type'] === 'document') {

            if (empty($this->payload['stored_media'])) {
                $this->logError('Document payload missing stored_media' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }

            $mediaId = $this->uploadLocalMediaToWhatsApp(
                $this->payload['stored_media']
            );

            if (!$mediaId) {
                return null;
            }

            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'document',
                'document' => [
                    'id'       => $mediaId,
                    'filename' => $this->payload['filename']
                        ?? ($this->payload['stored_media']['filename'] ?? 'document.pdf'),
                    'caption'  => $this->payload['caption'] ?? '',
                ]
            ];
        }

        return null;
    }
    
    private function uploadLocalMediaToWhatsApp(array $storedMedia): ?string
    {
        $channel = $this->inboundMessage->channel;
        $mimeType = $storedMedia['mime_type'] ?? 'application/octet-stream';

        $absolutePath = storage_path('app/public/' . $storedMedia['path']);

        if (!file_exists($absolutePath)) {
            $this->logError('Local media file missing' . $this->ctx([
                'path' => $absolutePath
            ]));
            return null;
        }

        $allowedMimes = [
            'image'    => ['image/jpeg', 'image/png'],
            'video'    => ['video/mp4', 'video/3gp'],
            'document' => ['application/pdf'],
        ];
        $type = $this->payload['type'] ?? null;
        if ($type && isset($allowedMimes[$type]) && !in_array($mimeType, $allowedMimes[$type], true)) {
            $this->logError("Unsupported WhatsApp {$type} mime type after storage" . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'mime_type' => $mimeType,
                'path' => $storedMedia['path'] ?? null,
            ]));
            return null;
        }

        $response = Http::withToken(
            $channel->getMeta('system_user_token')
        )->attach(
            'file',
            fopen($absolutePath, 'r'),
            basename($absolutePath)
        )->post(
            "https://graph.facebook.com/v18.0/" .
            $channel->getMeta('whatsapp_phone_number_id') .
            "/media",
            [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]
        );

        if (!$response->successful()) {
            $this->logError('WhatsApp media upload failed' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
                'response' => $response->json(),
            ]));
            return null;
        }

        return $response->json('id');
    }

}
