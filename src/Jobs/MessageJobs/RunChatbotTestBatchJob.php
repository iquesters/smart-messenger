<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ChatbotTestEntityService;

class RunChatbotTestBatchJob extends BaseJob
{
    protected string $runUid;

    protected function initialize(...$arguments): void
    {
        [$this->runUid] = $arguments;
    }

    public function process(): void
    {
        $service = new ChatbotTestEntityService();
        $service->assertRunnerSchemaReady();

        $run = $service->getRunByRunUid($this->runUid);
        if (!$run || in_array($run->status ?? null, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $userId = (int) ($run->triggered_by ?? $run->created_by ?? 0);
        $this->finalizeDispatchedItems($service, $run, $userId);
        $run = $service->getRunByRunUid($this->runUid);

        if (!$run || in_array($run->status ?? null, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $nextItem = $service->getNextPendingRunItem($this->runUid);
        if (!$nextItem) {
            $service->refreshRunCounters($this->runUid, $userId);
            $finalRun = $service->getRunByRunUid($this->runUid);
            $service->updateRunByRunUid($this->runUid, [
                'status' => 'completed',
                'completed_at_custom' => now(),
                'next_dispatch_at' => null,
            ], [
                'summary' => [
                    'total_cases' => $finalRun->total_cases ?? 0,
                    'processed_cases' => $finalRun->processed_cases ?? 0,
                    'passed_cases' => $finalRun->passed_cases ?? 0,
                    'failed_cases' => $finalRun->failed_cases ?? 0,
                    'completed_at' => now()->toDateTimeString(),
                ],
            ], $userId);
            return;
        }

        if (($run->status ?? null) === 'pending') {
            $service->updateRunByRunUid($this->runUid, [
                'status' => 'running',
                'started_at_custom' => $run->started_at_custom ?? now(),
            ], [], $userId);
        }

        $contact = $this->resolveContact($nextItem, $userId);
        $inboundMessage = $this->createInboundMessage($run, $nextItem, $userId);

        $service->updateRunItemByUid($nextItem->uid, [
            'inbound_message_id' => $inboundMessage->id,
            'status' => 'dispatched',
            'error_message' => null,
        ], [], $userId);

        $intervalMinutes = max(1, (int) (($run->meta['config']['interval_minutes'] ?? null) ?: 5));

        $service->updateRunByRunUid($this->runUid, [
            'last_dispatched_at' => now(),
            'next_dispatch_at' => now()->addMinutes($intervalMinutes),
            'last_index' => $nextItem->sequence_no ?? (($run->last_index ?? 0) + 1),
        ], [], $userId);

        $rawPayload = [
            'is_test' => true,
            'contact_name' => $nextItem->contact_name ?? 'Test Man',
            'test_run_uid' => $this->runUid,
            'test_run_item_uid' => $nextItem->uid,
        ];

        ForwardToChatbotJob::dispatch($inboundMessage, $rawPayload, $contact);
        self::dispatch($this->runUid)->delay(now()->addMinutes($intervalMinutes));
    }

    protected function resolveContact(object $item, int $userId): Contact
    {
        $identifier = $item->identifier ?? '9999999999';
        $contact = Contact::query()->where('identifier', $identifier)->first();

        if (!$contact) {
            $contact = Contact::create([
                'uid' => (string) Str::ulid(),
                'name' => $item->contact_name ?? 'Test Man',
                'identifier' => $identifier,
                'status' => Constants::ACTIVE,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } else {
            $contact->update([
                'name' => $item->contact_name ?? $contact->name,
                'status' => Constants::ACTIVE,
                'updated_by' => $userId,
            ]);
        }

        return $contact;
    }

    protected function createInboundMessage(object $run, object $item, int $userId): Message
    {
        $channelMetas = DB::table('channel_metas')->where('ref_parent', $run->channel_id)->pluck('meta_value', 'meta_key');
        $channelPhone = ($channelMetas['country_code'] ?? '') . ($channelMetas['whatsapp_number'] ?? '');

        $message = Message::create([
            'channel_id' => $run->channel_id,
            'integration_id' => $run->integration_id ?? null,
            'message_id' => 'test-in-' . strtolower((string) Str::ulid()),
            'from' => $item->identifier ?? '9999999999',
            'to' => $channelPhone ?: 'test-channel',
            'message_type' => 'text',
            'content' => $item->question,
            'timestamp' => now(),
            'status' => Constants::RECEIVED,
            'raw_payload' => [
                'is_test' => true,
                'contact_name' => $item->contact_name ?? 'Test Man',
                'test_run_uid' => $run->run_uid,
                'test_run_item_uid' => $item->uid,
                'question' => $item->question,
            ],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $message->setMeta('is_test', '1');
        $message->setMeta('chatbot_test_run_uid', $run->run_uid);
        $message->setMeta('chatbot_test_run_item_uid', $item->uid);
        if (!empty($item->chatbot_test_case_uid)) {
            $message->setMeta('chatbot_test_case_uid', $item->chatbot_test_case_uid);
        }

        return $message;
    }

    protected function finalizeDispatchedItems(ChatbotTestEntityService $service, object $run, int $userId): void
    {
        $items = $service->getRunItems($run->run_uid)->where('status', 'dispatched')->values();

        foreach ($items as $item) {
            $outboundMessages = Message::query()
                ->whereHas('metas', function ($query) use ($item) {
                    $query->where('meta_key', 'chatbot_test_run_item_uid')
                        ->where('meta_value', $item->uid);
                })
                ->orderBy('id')
                ->get();

            if ($outboundMessages->isEmpty()) {
                continue;
            }

            $actualAnswer = $outboundMessages
                ->map(fn (Message $message) => $this->messageToAssertionText($message))
                ->filter(fn ($value) => $value !== '')
                ->implode("\n\n");

            $assertion = $this->evaluateAssertions($item, $actualAnswer, $outboundMessages->count());

            $service->updateRunItemByUid($item->uid, [
                'outbound_message_id' => $outboundMessages->last()->id,
                'actual_answer' => $actualAnswer,
                'processed_at' => now(),
                'status' => $assertion['passed'] ? 'passed' : 'failed',
                'error_message' => $assertion['passed'] ? null : ($assertion['message'] ?? 'Assertion failed.'),
            ], [
                'assertion_result' => $assertion,
            ], $userId);
        }

        $service->refreshRunCounters($run->run_uid, $userId);
    }

    protected function evaluateAssertions(object $item, string $actualAnswer, int $replyCount): array
    {
        $checks = [];
        $normalizedActual = trim($actualAnswer);

        if (!empty($item->expected_answer)) {
            $expected = trim((string) $item->expected_answer);
            $checks['exact_match'] = [
                'expected' => $expected,
                'actual' => $normalizedActual,
                'passed' => $expected === $normalizedActual,
            ];
        }

        if (!empty($item->expected_contains)) {
            $needle = trim((string) $item->expected_contains);
            $checks['contains_match'] = [
                'expected' => $needle,
                'actual' => $normalizedActual,
                'passed' => str_contains(mb_strtolower($normalizedActual), mb_strtolower($needle)),
            ];
        }

        $passed = empty($checks) || collect($checks)->every(fn ($check) => $check['passed'] === true);

        return [
            'passed' => $passed,
            'reply_count' => $replyCount,
            'checks' => $checks,
            'message' => $passed ? 'Assertions passed.' : 'One or more assertions failed.',
        ];
    }

    protected function messageToAssertionText(Message $message): string
    {
        if ($message->message_type === 'text') {
            return trim((string) $message->content);
        }

        $caption = trim((string) $message->caption());
        if ($caption !== '') {
            return $caption;
        }

        return '[' . $message->message_type . ' reply]';
    }
}