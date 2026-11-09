<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

/**
 * Sends a human reply accepted by the Operator Inbox (V9, L3).
 *
 * chatbot-util writes an `operator_outbound_commands` row and this job's
 * `jobs` row in **one** transaction, so by the time this runs the command is
 * guaranteed to exist. The queue payload therefore carries only the command
 * uid: the reply text, the operator and the anchor inbound message are all
 * read back from that row, which means the payload cannot disagree with the
 * record, and no customer-facing text is duplicated into the queue.
 *
 * This job does not talk to Meta. It re-validates and then dispatches the
 * existing SendWhatsAppReplyJob, so there is exactly one WhatsApp sender in
 * the system and operator replies go out through the same code path, with
 * the same credentials and the same error handling, as every other reply.
 *
 * ## Why it re-validates
 *
 * The command was accepted when the operator pressed send. Queue latency,
 * retries and a backed-up worker mean this can run much later, by which time
 * the channel may have been deactivated or the 24-hour window may have
 * closed. Sending then would be rejected by Meta - or worse, accepted
 * against a channel the business no longer intends to use.
 */
class OperatorReplyJob extends BaseJob
{
    protected array $payload;

    protected function initialize(...$arguments): void
    {
        [$this->payload] = $arguments;
    }

    public function process(): void
    {
        $commandUid = $this->payload['command_uid'] ?? null;

        $this->logMethodStart($this->ctx(['command_uid' => $commandUid]));

        if (!$commandUid) {
            $this->logWarning('Operator reply job without a command uid' . $this->ctx([]));
            return;
        }

        $command = DB::table('operator_outbound_commands')
            ->where('uid', $commandUid)
            ->first();

        if (!$command) {
            // The transaction that should have written it rolled back, or the
            // row was removed. Nothing to send, and nothing to repair.
            $this->logWarning('Operator reply command not found' . $this->ctx([
                'command_uid' => $commandUid,
            ]));
            return;
        }

        if ($command->state !== 'dispatched') {
            // Already sent or already failed. A queue retry must not send the
            // customer a second copy of the same reply.
            $this->logInfo('Operator reply command is not dispatched; skipping' . $this->ctx([
                'command_uid' => $commandUid,
                'state' => $command->state,
            ]));
            return;
        }

        if ($command->message_id) {
            // Belt and braces: a linked message means it already went out.
            $this->logInfo('Operator reply already linked to a message; skipping' . $this->ctx([
                'command_uid' => $commandUid,
                'message_id' => $command->message_id,
            ]));
            return;
        }

        $inbound = Message::find($command->anchor_inbound_message_id);
        if (!$inbound) {
            $this->failCommand($commandUid, 'anchor_message_missing');
            return;
        }

        $channel = $inbound->channel;
        if (!$channel) {
            $this->failCommand($commandUid, 'channel_missing');
            return;
        }

        if (($channel->status ?? 'active') !== 'active') {
            // The business turned this channel off between accepting the
            // reply and sending it.
            $this->failCommand($commandUid, 'channel_inactive');
            return;
        }

        if ($this->replyWindowClosed($inbound)) {
            // WhatsApp would reject this. Recording it as failed lets the
            // inbox show the operator why, instead of a reply that silently
            // never arrives.
            $this->failCommand($commandUid, 'reply_window_closed');
            return;
        }

        $this->logInfo('Dispatching operator reply to the WhatsApp sender' . $this->ctx([
            'command_uid' => $commandUid,
            'inbound_message_id' => $inbound->id,
            'channel_id' => $channel->id,
        ]));

        // The metas below are what lets the projector classify the resulting
        // outbound row as a human reply and credit it against the right
        // inbound message (§8.2). Without them the row would be `unknown`
        // and would give no Unanswered credit at all.
        SendWhatsAppReplyJob::dispatch($inbound, [
            'type' => 'text',
            'text' => $command->body,
            'created_by_override' => $command->user_id,
            '_actor' => 'operator',
            '_operator_command_uid' => $command->uid,
            '_operator_anchor_inbound_message_id' => $command->anchor_inbound_message_id,
        ]);

        $this->logMethodEnd($this->ctx(['command_uid' => $commandUid]));
    }

    /**
     * Whether WhatsApp's 24-hour customer service window has closed.
     *
     * Measured from the anchor inbound message, which is the latest customer
     * message at the time the operator pressed send.
     */
    private function replyWindowClosed(Message $inbound): bool
    {
        $timestamp = $inbound->timestamp;
        if (!$timestamp) {
            return true;
        }

        return $timestamp->copy()->addHours(24)->isPast();
    }

    /**
     * Record that the reply will not be sent, and why.
     *
     * The projector reads this and the inbox shows a failed bubble. Leaving
     * the row `dispatched` would show the operator a reply that is
     * permanently in flight.
     */
    private function failCommand(string $commandUid, string $errorCode): void
    {
        $this->logWarning('Operator reply rejected before sending' . $this->ctx([
            'command_uid' => $commandUid,
            'error_code' => $errorCode,
        ]));

        DB::table('operator_outbound_commands')
            ->where('uid', $commandUid)
            ->where('state', 'dispatched')
            ->update([
                'state' => 'failed',
                'error_code' => $errorCode,
                'updated_at' => now(),
            ]);
    }

}
