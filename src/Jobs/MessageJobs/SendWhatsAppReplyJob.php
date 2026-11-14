<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Http\Client\ConnectionException;
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
        $commandUid = $this->payload['_operator_command_uid'] ?? null;
        if (!$commandUid) {
            if (($this->payload['_actor'] ?? null) === 'ai'
                || ($this->payload['_inbox_actor'] ?? null) === 'ai') {
                $this->sendAiUnderHandoverGuard();
            } else {
                $this->sendNow();
            }
            return;
        }
        // All workers on the same MariaDB server serialize one command here.
        // The lock is held across the Meta request; a retry cannot send a
        // second copy while the first worker is still in flight.
        $lockName = 'operator-reply:' . $commandUid;
        $mysql = \Illuminate\Support\Facades\DB::getDriverName() === 'mysql';
        if ($mysql) {
            $acquired = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT GET_LOCK(?, 10) AS acquired', [$lockName]
            );
            if ((int) ($acquired->acquired ?? 0) !== 1) {
                throw new \RuntimeException('operator_reply_lock_unavailable');
            }
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($commandUid) {
                $command = \Illuminate\Support\Facades\DB::table('operator_outbound_commands')
                    ->where('uid', $commandUid)->lockForUpdate()->first();
                if (!$command || $command->state !== 'dispatched' || $command->message_id) {
                    return;
                }
                $alreadySent = \Illuminate\Support\Facades\DB::table('message_metas')
                    ->where('meta_key', 'operator_command_uid')
                    ->where('meta_value', $commandUid)->value('ref_parent');
                if ($alreadySent) {
                    \Illuminate\Support\Facades\DB::table('operator_outbound_commands')
                        ->where('uid', $commandUid)->where('state', 'dispatched')
                        ->update(['state' => 'sent', 'message_id' => $alreadySent, 'updated_at' => now()]);
                    return;
                }
                $conversation = \Illuminate\Support\Facades\DB::table('operator_conversations')
                    ->where('id', $command->conversation_id)
                    ->where('organisation_id', $command->organisation_id)
                    ->lockForUpdate()->first();
                if (!$conversation || $conversation->handover_state !== 'owned'
                    || (int) $conversation->owner_user_id !== (int) $command->user_id
                    || (int) $conversation->channel_id !== (int) $this->inboundMessage->channel_id
                    || (string) $conversation->participant_key !== (string) $this->inboundMessage->from
                    || (int) $command->anchor_inbound_message_id !== (int) $this->inboundMessage->id) {
                    $this->failOperatorCommand('ownership_or_scope_changed');
                    return;
                }
                $this->sendNow();
            });
        } finally {
            if ($mysql) {
                \Illuminate\Support\Facades\DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }

    private function sendAiUnderHandoverGuard(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        if ($db::getDriverName() !== 'mysql'
            || !\Illuminate\Support\Facades\Schema::hasTable('operator_conversations')) {
            $this->sendNow();
            return;
        }
        $channel = $this->inboundMessage->channel;
        $organisations = $channel?->organisations()->pluck('organisations.id');
        if (!$channel || !$this->inboundMessage->from
            || !$organisations || $organisations->count() !== 1) {
            $this->logWarning('AI send suppressed: handover lock scope unavailable');
            return;
        }
        $schema = $db::selectOne('SELECT DATABASE() AS name')->name ?? '';
        if (!$schema) {
            $this->logWarning('AI send suppressed: database identity unavailable');
            return;
        }
        $key = $schema . ':' . $organisations->first() . ':' . $channel->id
            . ':' . $this->inboundMessage->from;
        $name = 'operator-handover:' . substr(hash('sha256', $key), 0, 40);
        $acquired = $db::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            // A delayed AI response is never worth racing a human takeover.
            throw new \RuntimeException('ai_handover_lock_unavailable');
        }
        try {
            $this->sendNow();
        } finally {
            $db::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }

    private function sendNow(): void
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
            $this->failOperatorCommand('send_validation_failed');
            return;
        }

        if (!empty($this->payload['_operator_command_uid'])) {
            if (($channel->status ?? 'active') !== 'active') {
                $this->failOperatorCommand('channel_inactive');
                return;
            }
            if (!$this->inboundMessage->timestamp
                || $this->inboundMessage->timestamp->copy()->addHours(24)->isPast()) {
                $this->failOperatorCommand('reply_window_closed');
                return;
            }
        }

        $phoneNumberId = $channel->getMeta('whatsapp_phone_number_id');
        $token = $channel->getMeta('system_user_token');

        if ((!$phoneNumberId || !$token) && $channel
            && empty($this->payload['_operator_command_uid'])) {
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
            $this->failOperatorCommand('channel_credentials_unavailable');
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
            $this->failOperatorCommand('reply_payload_invalid');
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
        if ((($this->payload['_actor'] ?? null) === 'ai'
                || ($this->payload['_inbox_actor'] ?? null) === 'ai')
            && $this->humanHasTakenOver($this->inboundMessage)) {
            $this->logWarning('AI reply suppressed: human handover active' . $this->ctx([
                'inbound_message_id' => $this->inboundMessage->id,
            ]));
            return;
        }

        try {
            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                $requestPayload
            );
        } catch (ConnectionException $error) {
            if (empty($this->payload['_operator_command_uid'])) {
                throw $error; // Preserve the existing Laravel retry path for other sends.
            }
            // A timeout does not prove rejection: Meta may have accepted the
            // request. Commit a terminal outcome so queue retries cannot resend.
            $this->recordUncertainOperatorDelivery($channel, $to, 'connection_failure');
            return;
        }

        if (!$response->successful()) {
            $error = 'WhatsApp API send failed';
            $errorDetail = is_array($response->json()) ? $response->json() : null;
            $context = [
                'inbound_message_id' => $this->inboundMessage->id,
                'http_status' => $response->status(),
                'response' => $errorDetail,
            ];
            $this->logError($error . $this->ctx($context));
            $this->saveFailedMessage($channel, $to, $error, $context, $errorDetail);
            $this->failOperatorCommand('meta_error');
            return;
        }

        $waMessageId = $response->json('messages.0.id');
        if (!is_string($waMessageId) || trim($waMessageId) === '') {
            if (!empty($this->payload['_operator_command_uid'])) {
                $this->recordUncertainOperatorDelivery($channel, $to, 'message_id_missing');
                return;
            }
            $error = 'WhatsApp send succeeded but no message_id returned';
            $errorDetail = is_array($response->json()) ? $response->json() : null;
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
         * `_inbox_actor` is the AI producer's persisted attribution key and
         * now also participates in the final send-time handover gate. The
         * reply anchor prevents crediting a newer customer question.
         */
        foreach ([
            '_actor' => 'actor',
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

    private function recordUncertainOperatorDelivery(Channel $channel, string $to, string $reason): void
    {
        $context = [
            'inbound_message_id' => $this->inboundMessage->id,
            'command_uid' => $this->payload['_operator_command_uid'],
            'reason' => $reason,
        ];
        $this->logWarning('Operator reply delivery is uncertain; automatic resend disabled' . $this->ctx($context));
        $this->saveFailedMessage($channel, $to, 'WhatsApp delivery outcome unknown', $context);
        $this->failOperatorCommand('meta_delivery_uncertain');
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
            'message_id'    => 'failed:' . (string) \Illuminate\Support\Str::uuid(),
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

    private function failOperatorCommand(string $errorCode): void
    {
        $uid = $this->payload['_operator_command_uid'] ?? null;
        if (!$uid) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('operator_outbound_commands')
            ->where('uid', $uid)->where('state', 'dispatched')
            ->update(['state' => 'failed', 'error_code' => $errorCode, 'updated_at' => now()]);
    }

    /**
     * Whether a human owns this conversation right now.
     *
     * The inbound phone number is not a chat_sessions.contact_uid. Resolve
     * the Contact ULID within the receiving channel's organisation and the
     * exact chatbot integration UID, then inspect human-origin transitions.
     * An operator release after this inbound also suppresses the old AI turn:
     * only a *new* customer message after release may be answered by AI.
     */
    private function humanHasTakenOver($inbound): bool
    {
        try {
            $channel = $inbound->channel;
            if (!\Illuminate\Support\Facades\Schema::hasTable('operator_conversations')) {
                return false; // Older deployments have no Operator Inbox commands.
            }
            $organisationIds = $channel?->organisations()->pluck('organisations.id');
            if (!$channel || !$inbound->from || !$organisationIds || $organisationIds->count() !== 1) {
                throw new \RuntimeException('ai_send_scope_unavailable');
            }
            $conversation = \Illuminate\Support\Facades\DB::table('operator_conversations')
                ->where('channel_id', $channel->id)
                ->where('organisation_id', $organisationIds->first())
                ->where('participant_key', $inbound->from)
                ->first(['handover_state', 'owner_user_id']);
            if (!$conversation) {
                return false;
            }
            if ($conversation->handover_state === 'owned' && $conversation->owner_user_id) {
                return true;
            }
            $contactUid = \Iquesters\SmartMessenger\Models\Contact::query()
                ->where('identifier', $inbound->from)
                ->whereHas('organisations', function ($query) use ($organisationIds) {
                    $query->where('organisations.id', $organisationIds->first());
                })->value('uid');
            $integrationUid = app(\Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService::class)
                ->resolveUidFromChannel($channel);
            if (!$contactUid || !$integrationUid || !$inbound->timestamp) {
                throw new \RuntimeException('ai_send_identity_unavailable');
            }
            $session = app(\Iquesters\SmartMessenger\Services\ChatSessionLookupService::class)
                ->findLatestActive($contactUid, $integrationUid);
            if (!$session) {
                throw new \RuntimeException('ai_send_session_unavailable');
            }
            $entries = is_string($session->context_json)
                ? json_decode($session->context_json, true, 512, JSON_THROW_ON_ERROR)
                : $session->context_json;
            if (!is_array($entries)) {
                throw new \RuntimeException('ai_send_context_unavailable');
            }
            $latestHuman = null;
            foreach ($entries as $entry) {
                if (!is_array($entry) || !in_array($entry['source'] ?? null,
                        ['operator_inbox', 'laravel_agent_ui'], true)) {
                    continue;
                }
                $handover = data_get($entry, 'ccx_state_snapshot.payload.human_handover');
                if (is_array($handover) && array_key_exists('active', $handover)) {
                    $latestHuman = $handover;
                }
            }
            if (!$latestHuman) {
                return false; // A CCX-initiated handover may send its own acknowledgement.
            }
            if ((bool) ($latestHuman['active'] ?? false)) {
                return true;
            }
            $ended = $latestHuman['ended_utc'] ?? null;
            if (!$ended) {
                throw new \RuntimeException('ai_send_release_timestamp_unavailable');
            }
            return \Carbon\CarbonImmutable::parse($ended)->greaterThan($inbound->timestamp);
        } catch (\Throwable $e) {
            $this->logWarning('AI send suppressed because handover scope could not be verified' . $this->ctx([
                'inbound_message_id' => $inbound->id ?? null,
                'error_type' => get_class($e),
            ]));
            return true;
        }
    }

    private function buildContentForDatabase(string $type): string
    {
        if ($type === 'text') {
            return is_string($this->payload['text'] ?? null) ? $this->payload['text'] : '';
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
        $type = $this->payload['type'] ?? null;
        if ($type === 'text') {
            if (!is_string($this->payload['text'] ?? null) || trim($this->payload['text']) === '') {
                $this->logWarning('WhatsApp text payload is empty or invalid' . $this->ctx([
                    'inbound_message_id' => $this->inboundMessage->id,
                ]));
                return null;
            }
            return [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $this->payload['text']]
            ];
        }

        if ($type === 'image') {

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

        if ($type === 'video') {

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

        if ($type === 'document') {

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
