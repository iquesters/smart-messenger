<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ChatbotV3OutboundJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ForwardToAgentJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ForwardToChatbotJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ProcessChatbotResponseJob;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesOperatorInboxTables;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\SmartMessengerServiceProvider;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;
use Mockery;
use Orchestra\Testbench\TestCase;

/** Executable persistence contract, isolated from the package's misordered migration runner. */
class OperatorInboxHandoverMetadataContractTest extends TestCase
{
    use CreatesChatSessionsTable, CreatesOperatorInboxTables;

    protected function getPackageProviders($app)
    {
        return [SmartMessengerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Package job telemetry writes a separate completed_jobs table that
        // is unrelated to the message/metadata contract under test.
        Event::forget(\Illuminate\Queue\Events\JobProcessed::class);

        // Run the package's actual migrations in dependency order. Its
        // integration-id migration has a filename preceding CREATE messages.
        foreach ([
            '2025_12_11_000000_create_provider_channels_table.php',
            '2024_12_11_000003_create_contacts_table.php',
            '2025_12_11_000001_create_channels_table.php',
            '2025_12_11_000002_create_messages_table.php',
            '2025_02_04_000006_add_integration_id_to_messages_table.php',
            '2025_12_11_000004_create_message_metas_table.php',
        ] as $file) {
            (require __DIR__ . '/../../../database/migrations/' . $file)->up();
        }
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->string('uid')->unique();
            $table->string('name');
        });
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('model_has_organisations', function (Blueprint $table) {
            $table->unsignedBigInteger('organisation_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        DB::table('queues')->insert(['uid' => 'handover-queue', 'name' => 'ForwardToAgentJob']);
    }

    /** @dataProvider handoverReasons */
    public function test_handover_reason_and_ack_are_persisted_on_the_same_inbound(string $reason): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        $inbound = $this->inbound($reason);
        Http::fake(function () use ($inbound, $reason) {
            $this->assertSame($reason, $inbound->getMeta('human_handover_reason'),
                'The acknowledgement reached Meta before its inbound reason was persisted');
            return Http::response(['messages' => [['id' => 'wamid.' . $reason]]], 200);
        });

        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Connecting you now.']]],
            'actions' => [$this->handoverAction($reason)],
        ], 10, 'final'))->handle();

        $outbound = Message::where('message_id', 'wamid.' . $reason)->firstOrFail();
        $this->assertSame($reason, $inbound->getMeta('human_handover_reason'));
        $this->assertSame('handover-queue', $inbound->getMeta('chatbot_handover_action_id'));
        $this->assertSame('ai', $outbound->getMeta('actor'));
        $this->assertSame('final', $outbound->getMeta('ai_kind'));
        $this->assertSame((string) $inbound->id, $outbound->getMeta('reply_to_message_id'));
        $this->assertSame('1', $outbound->getMeta('handover_trigger'));
        $this->assertSame($inbound->channel_id, $outbound->channel_id);
        $this->assertSame(10, $outbound->integration_id);
        $this->assertSame(0, $outbound->created_by);
        Http::assertSentCount(1);
    }

    public static function handoverReasons(): array
    {
        return array_map(
            fn ($reason) => [$reason],
            [
                'explicit_human_agent_request', 'user_confirmed_order_handover',
                'order_unknown', 'order_disabled', 'max_attempts',
                'tracking_missing', 'items_missing',
            ]
        );
    }

    public function test_progress_is_sent_but_carries_no_handover_credit(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.progress']]], 200)]);
        $inbound = $this->inbound('progress');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Searching inventory.']]],
            'actions' => [],
        ], 10, 'progress'))->handle();

        $outbound = Message::where('message_id', 'wamid.progress')->firstOrFail();
        $this->assertSame('ai', $outbound->getMeta('actor'));
        $this->assertSame('progress', $outbound->getMeta('ai_kind'));
        $this->assertSame((string) $inbound->id, $outbound->getMeta('reply_to_message_id'));
        $this->assertNull($outbound->getMeta('handover_trigger'));
        Http::assertSentCount(1);
    }

    public function test_v3_queue_hop_preserves_progress_kind_and_inbound_identity(): void
    {
        Bus::fake([ProcessChatbotResponseJob::class]);
        $inbound = $this->inbound('queue-hop');
        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveIdFromMessage')->once()->andReturn(10);
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        (new ChatbotV3OutboundJob([
            'message_id' => $inbound->message_id,
            'kind' => 'progress',
            'chatbot_response' => [
                'session_id' => 'session-1',
                'messages' => [['type' => 'text', 'content' => ['text' => 'Searching.']]],
            ],
        ]))->handle();

        Bus::assertDispatched(ProcessChatbotResponseJob::class, function ($job) use ($inbound) {
            $reflection = new \ReflectionObject($job);
            foreach (['kind' => 'progress', 'integrationId' => 10] as $key => $expected) {
                $property = $reflection->getProperty($key);
                $property->setAccessible(true);
                $this->assertSame($expected, $property->getValue($job));
            }
            $property = $reflection->getProperty('inboundMessage');
            $property->setAccessible(true);
            return $property->getValue($job)->id === $inbound->id;
        });
    }

    public function test_old_three_argument_job_keeps_ambiguous_text_conservative(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.legacy']]], 200)]);
        $inbound = $this->inbound('legacy');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Legacy response.']]],
        ], 10))->handle();

        $outbound = Message::where('message_id', 'wamid.legacy')->firstOrFail();
        $this->assertSame('ai', $outbound->getMeta('actor'));
        $this->assertNull($outbound->getMeta('ai_kind'));
        $this->assertSame((string) $inbound->id, $outbound->getMeta('reply_to_message_id'));
    }

    public function test_final_product_text_uses_the_same_reply_correlation(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.product']]], 200)]);
        $inbound = $this->inbound('product');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'product', 'content' => [
                'title' => 'Brown silk saree', 'sku' => 'SAREE-1',
            ]]],
        ], 10, 'final'))->handle();

        $outbound = Message::where('message_id', 'wamid.product')->firstOrFail();
        $this->assertSame('ai', $outbound->getMeta('actor'));
        $this->assertSame('final', $outbound->getMeta('ai_kind'));
        $this->assertSame((string) $inbound->id, $outbound->getMeta('reply_to_message_id'));
        $this->assertNull($outbound->getMeta('handover_trigger'));
    }

    public function test_legacy_deployment_without_inbox_projection_still_sends_ai(): void
    {
        $this->createChatSessionsTable();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.unchanged']]], 200)]);
        $inbound = $this->inbound('unchanged');
        DB::table('chat_sessions')->insert([
            'session_id' => 'active-session',
            'contact_uid' => $inbound->from,
            'integration_id' => 'chatbot-uid',
            'context_json' => json_encode([[
                'ccx_state_snapshot' => ['payload' => ['human_handover' => [
                    'active' => true, 'reason' => 'explicit_human_agent_request',
                    'status' => 'active',
                ]]],
            ]]),
            'created_at' => now(), 'last_active_at' => now(),
        ]);
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Final response.']]],
        ], 10, 'final'))->handle();

        Http::assertSentCount(1);
        $this->assertSame('ai', Message::where('message_id', 'wamid.unchanged')
            ->firstOrFail()->getMeta('actor'));
    }

    public function test_owned_projection_suppresses_queued_ai_but_not_human_reply(): void
    {
        $inbound = $this->inbound('guard-owned');
        $this->createInboxOwnership($inbound, 'owned', 7);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.human']]], 200)]);

        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'Stale answer', '_inbox_actor' => 'ai',
            '_ai_kind' => 'final',
        ]))->handle();
        Http::assertNothingSent();
        $this->assertSame(1, Message::count());

        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'I can help', '_actor' => 'operator',
            'created_by_override' => 7,
        ]))->handle();
        Http::assertSentCount(1);
        $this->assertSame('operator', Message::where('message_id', 'wamid.human')
            ->firstOrFail()->getMeta('actor'));
    }

    public function test_owned_projection_routes_new_inbound_to_human_even_without_a_session(): void
    {
        $this->createChatSessionsTable();
        $inbound = $this->inbound('owned-no-session');
        $this->createInboxOwnership($inbound, 'owned', 7);
        $contact = Contact::create([
            'uid' => (string) Str::ulid(), 'name' => 'Customer',
            'identifier' => $inbound->from, 'status' => 'active',
        ]);
        DB::table('model_has_organisations')->insert([
            'organisation_id' => 1, 'model_type' => Contact::class,
            'model_id' => $contact->id,
        ]);
        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake();

        (new ForwardToChatbotJob($inbound, [], $contact))->handle();

        Http::assertNothingSent();
        Bus::assertDispatched(ForwardToAgentJob::class);
        $this->assertSame('human_agent', $inbound->getMeta('human_route_decision'));
    }

    public function test_old_queued_ai_response_is_suppressed_after_takeover(): void
    {
        $inbound = $this->inbound('legacy-owned');
        $this->createInboxOwnership($inbound, 'owned', 7);
        Http::fake();

        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Late response']]],
        ], 10))->handle();

        Http::assertNothingSent();
        $this->assertSame(1, Message::count());
    }

    public function test_progress_is_suppressed_after_takeover(): void
    {
        $inbound = $this->inbound('progress-owned');
        $this->createInboxOwnership($inbound, 'owned', 7);
        Http::fake();

        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Checking inventory']]],
        ], 10, 'progress'))->handle();

        Http::assertNothingSent();
    }

    public function test_other_tenant_ownership_does_not_control_this_ai_reply(): void
    {
        $inbound = $this->inbound('tenant-ai');
        $this->createInboxOwnership($inbound, 'owned', 7);
        DB::table('operator_conversations')->update(['organisation_id' => 2]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.tenant-ai']]], 200)]);

        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Scoped answer']]],
        ], 10, 'final'))->handle();

        Http::assertSentCount(1);
        $this->assertSame('ai', Message::where('message_id', 'wamid.tenant-ai')
            ->firstOrFail()->getMeta('actor'));
    }

    public function test_release_after_the_inbound_suppresses_queued_ai_but_new_inbound_is_allowed(): void
    {
        $this->createChatSessionsTable();
        $inbound = $this->inbound('guard-released');
        $inbound->timestamp = now()->subMinutes(10);
        $inbound->save();
        $this->createInboxOwnership($inbound, 'none', null);
        $contact = Contact::create([
            'uid' => (string) Str::ulid(), 'name' => 'Customer',
            'identifier' => $inbound->from, 'status' => 'active',
        ]);
        DB::table('model_has_organisations')->insert([
            'organisation_id' => 1, 'model_type' => Contact::class,
            'model_id' => $contact->id,
        ]);
        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromChannel')->andReturn('chatbot-uid');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);
        DB::table('chat_sessions')->insert([
            'session_id' => 'released-session', 'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid',
            'context_json' => json_encode([[
                'source' => 'operator_inbox', 'ccx_state_snapshot' => ['payload' => [
                    'human_handover' => ['active' => false, 'status' => 'closed',
                        'ended_utc' => now()->subMinutes(5)->toIso8601String()],
                ]],
            ]]),
            'created_at' => now()->subHour(), 'last_active_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.after-release']]], 200)]);
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'Stale answer', '_inbox_actor' => 'ai',
        ]))->handle();
        Http::assertNothingSent();

        $inbound->timestamp = now();
        $inbound->save();
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'New answer', '_inbox_actor' => 'ai',
        ]))->handle();
        Http::assertSentCount(1);
    }

    public function test_operator_reply_job_sends_once_and_persists_human_credit_metadata(): void
    {
        $inbound = $this->inbound('operator-command');
        $this->createInboxOwnership($inbound, 'owned', 7);
        $this->createOperatorOutboundCommandsTable();
        $uid = (string) Str::ulid();
        DB::table('operator_outbound_commands')->insert([
            'uid' => $uid, 'organisation_id' => 1, 'conversation_id' => 1,
            'user_id' => 7, 'client_message_id' => (string) Str::uuid(),
            'anchor_inbound_message_id' => $inbound->id,
            'message_type' => 'text', 'body' => 'I can help you.',
            'state' => 'dispatched', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.operator-command']]], 200)]);
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob([
            'command_uid' => $uid,
        ]))->handle();

        $outbound = Message::where('message_id', 'wamid.operator-command')->firstOrFail();
        $command = DB::table('operator_outbound_commands')->where('uid', $uid)->first();
        $this->assertSame('sent', $command->state);
        $this->assertSame($outbound->id, $command->message_id);
        $this->assertSame('operator', $outbound->getMeta('actor'));
        $this->assertSame($uid, $outbound->getMeta('operator_command_uid'));
        $this->assertSame((string) $inbound->id,
            $outbound->getMeta('operator_anchor_inbound_message_id'));
        $this->assertSame(7, $outbound->created_by);

        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob([
            'command_uid' => $uid,
        ]))->handle();
        Http::assertSentCount(1);
    }

    public function test_failed_whatsapp_operator_reply_is_not_reported_sent(): void
    {
        $inbound = $this->inbound('operator-failed');
        $this->createInboxOwnership($inbound, 'owned', 7);
        $this->createOperatorOutboundCommandsTable();
        $uid = (string) Str::ulid();
        DB::table('operator_outbound_commands')->insert([
            'uid' => $uid, 'organisation_id' => 1, 'conversation_id' => 1,
            'user_id' => 7, 'client_message_id' => (string) Str::uuid(),
            'anchor_inbound_message_id' => $inbound->id,
            'message_type' => 'text', 'body' => 'I can help you.',
            'state' => 'dispatched', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['error' => 'rejected'], 500)]);
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob([
            'command_uid' => $uid,
        ]))->handle();

        $this->assertSame('failed', DB::table('operator_outbound_commands')
            ->where('uid', $uid)->value('state'));
        $this->assertSame(2, Message::count());
        $this->assertSame('failed', Message::latest('id')->firstOrFail()->status);
    }

    /** @dataProvider operatorSendErrors */
    public function test_operator_send_errors_are_terminal_and_replays_never_resend(
        string $scenario, mixed $responseBody, int $status, string $errorCode, int $expectedRequests
    ): void {
        $inbound = $this->inbound('send-error');
        $this->createInboxOwnership($inbound, 'owned', 7);
        $this->createOperatorOutboundCommandsTable();
        $uid = (string) Str::ulid();
        DB::table('operator_outbound_commands')->insert([
            'uid' => $uid, 'organisation_id' => 1, 'conversation_id' => 1,
            'user_id' => 7, 'client_message_id' => (string) Str::uuid(),
            'anchor_inbound_message_id' => $inbound->id,
            'message_type' => 'text', 'body' => 'Human reply',
            'state' => 'dispatched', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'type' => 'text', 'text' => 'Human reply', '_actor' => 'operator',
            'created_by_override' => 7, '_operator_command_uid' => $uid,
        ];
        if ($scenario === 'credentials') {
            DB::table('channel_metas')->where('ref_parent', $inbound->channel_id)
                ->where('meta_key', 'system_user_token')->delete();
        } elseif ($scenario === 'payload') {
            $payload['type'] = 'image'; // Missing stored_media cannot produce a send request.
        } elseif ($scenario === 'missing_type') {
            unset($payload['type']);
        } elseif ($scenario === 'empty_text') {
            $payload['text'] = ' ';
        } elseif ($scenario === 'invalid_text') {
            $payload['text'] = ['invalid'];
        }
        $attempts = 0;
        Http::fake(function () use (&$attempts, $scenario, $responseBody, $status) {
            $attempts++;
            if ($scenario === 'connection') {
                throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
            }
            return Http::response($responseBody, $status);
        });

        $sender = new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, $payload);
        $sender->handle();

        $command = DB::table('operator_outbound_commands')->where('uid', $uid)->first();
        $this->assertSame('failed', $command->state);
        $this->assertSame($errorCode, $command->error_code);
        $this->assertNull($command->message_id);
        $this->assertSame(2, Message::count());
        $this->assertSame('failed', Message::latest('id')->firstOrFail()->status);
        $this->assertStringStartsWith('failed:', Message::latest('id')->firstOrFail()->message_id);

        // Retry both the queued sender and its parent job. Neither may POST
        // again, including when Meta might already have accepted the first send.
        $sender->handle();
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob(['command_uid' => $uid]))->handle();
        $this->assertSame($expectedRequests, $attempts);
        $this->assertSame(2, Message::count());
    }

    public static function operatorSendErrors(): array
    {
        return [
            'missing credentials' => ['credentials', [], 200, 'channel_credentials_unavailable', 0],
            'invalid payload' => ['payload', [], 200, 'reply_payload_invalid', 0],
            'missing type' => ['missing_type', [], 200, 'reply_payload_invalid', 0],
            'empty text' => ['empty_text', [], 200, 'reply_payload_invalid', 0],
            'invalid text type' => ['invalid_text', [], 200, 'reply_payload_invalid', 0],
            'accepted without ID' => ['response', ['messages' => []], 200, 'meta_delivery_uncertain', 1],
            'empty accepted response' => ['response', '', 202, 'meta_delivery_uncertain', 1],
            'scalar JSON' => ['response', '"accepted"', 200, 'meta_delivery_uncertain', 1],
            'invalid JSON' => ['response', '<html>gateway</html>', 200, 'meta_delivery_uncertain', 1],
            'invalid ID type' => ['response', ['messages' => [['id' => ['invalid']]]], 200, 'meta_delivery_uncertain', 1],
            'blank ID' => ['response', ['messages' => [['id' => ' ']]], 200, 'meta_delivery_uncertain', 1],
            'connection failure' => ['connection', [], 200, 'meta_delivery_uncertain', 1],
            'non-JSON rejection' => ['response', 'gateway error', 500, 'meta_error', 1],
        ];
    }

    public function test_non_operator_connection_failure_preserves_laravel_retry_behavior(): void
    {
        $inbound = $this->inbound('legacy-timeout');
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
        });

        $this->expectException(\Illuminate\Http\Client\ConnectionException::class);
        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'Existing Laravel reply',
        ]))->handle();
    }

    public function test_non_operator_missing_message_id_still_records_a_failed_laravel_message(): void
    {
        $inbound = $this->inbound('legacy-no-id');
        Http::fake(['*' => Http::response('"accepted"', 200)]);

        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob($inbound, [
            'type' => 'text', 'text' => 'Existing Laravel reply',
        ]))->handle();

        Http::assertSentCount(1);
        $this->assertSame(2, Message::count());
        $this->assertSame('failed', Message::latest('id')->firstOrFail()->status);
    }

    /** @dataProvider changedReplyScopes */
    public function test_changed_reply_ownership_or_tenant_never_reaches_meta(string $field, mixed $value): void
    {
        $inbound = $this->inbound('changed-scope');
        $this->createInboxOwnership($inbound, 'owned', 7);
        $this->createOperatorOutboundCommandsTable();
        $uid = (string) Str::ulid();
        DB::table('operator_outbound_commands')->insert([
            'uid' => $uid, 'organisation_id' => 1, 'conversation_id' => 1,
            'user_id' => 7, 'client_message_id' => (string) Str::uuid(),
            'anchor_inbound_message_id' => $inbound->id,
            'message_type' => 'text', 'body' => 'Human reply',
            'state' => 'dispatched', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('operator_conversations')->where('id', 1)->update([$field => $value]);
        Http::fake();

        (new \Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob([
            'command_uid' => $uid,
        ]))->handle();

        Http::assertNothingSent();
        $this->assertSame('ownership_or_scope_changed', DB::table('operator_outbound_commands')
            ->where('uid', $uid)->value('error_code'));
    }

    public static function changedReplyScopes(): array
    {
        return [
            'other tenant' => ['organisation_id', 2],
            'other operator' => ['owner_user_id', 8],
            'released' => ['handover_state', 'none'],
            'other channel' => ['channel_id', 999],
            'other customer' => ['participant_key', 'another-customer'],
        ];
    }

    public function test_unknown_reason_is_persisted_without_inventing_an_initiator(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.unknown']]], 200)]);
        $inbound = $this->inbound('unknown');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Connecting you now.']]],
            'actions' => [$this->handoverAction('future_reason')],
        ], 10, 'final'))->handle();

        $outbound = Message::where('message_id', 'wamid.unknown')->firstOrFail();
        $this->assertSame('future_reason', $inbound->getMeta('human_handover_reason'));
        $this->assertSame('1', $outbound->getMeta('handover_trigger'));
    }

    public function test_old_action_without_reason_still_marks_an_unknown_ack(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.no-reason']]], 200)]);
        $inbound = $this->inbound('no-reason');
        $action = $this->handoverAction('order_unknown');
        $action['data'] = [$action['data'][1]];
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Connecting you now.']]],
            'actions' => [$action],
        ], 10, 'final'))->handle();

        $outbound = Message::where('message_id', 'wamid.no-reason')->firstOrFail();
        $this->assertNull($inbound->getMeta('human_handover_reason'));
        $this->assertSame('handover-queue', $inbound->getMeta('chatbot_handover_action_id'));
        $this->assertSame('1', $outbound->getMeta('handover_trigger'));
    }

    public function test_failed_whatsapp_send_never_creates_an_acknowledgement_row(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake(['*' => Http::response(['error' => 'rejected'], 500)]);
        $inbound = $this->inbound('failed');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Connecting you now.']]],
            'actions' => [$this->handoverAction('order_unknown')],
        ], 10, 'final'))->handle();

        $this->assertSame('order_unknown', $inbound->getMeta('human_handover_reason'));
        $this->assertSame(2, Message::count());
        $this->assertNull(Message::latest('id')->firstOrFail()->getMeta('handover_trigger'));
        Http::assertSentCount(1);
    }

    public function test_same_customer_on_another_channel_keeps_separate_metadata(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.tenant']]], 200)]);
        $otherChannelInbound = $this->inbound('other-channel');
        $inbound = $this->inbound('tenant');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [['type' => 'text', 'content' => ['text' => 'Connecting you now.']]],
            'actions' => [$this->handoverAction('explicit_human_agent_request')],
        ], 10, 'final'))->handle();

        $outbound = Message::where('message_id', 'wamid.tenant')->firstOrFail();
        $this->assertSame($inbound->channel_id, $outbound->channel_id);
        $this->assertNotSame($otherChannelInbound->channel_id, $outbound->channel_id);
        $this->assertNull($otherChannelInbound->getMeta('human_handover_reason'));
        $this->assertNull($otherChannelInbound->getMeta('chatbot_handover_action_id'));
    }

    public function test_only_the_action_linked_first_text_is_marked_as_handover_ack(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fakeSequence()
            ->push(['messages' => [['id' => 'wamid.first']]], 200)
            ->push(['messages' => [['id' => 'wamid.second']]], 200);
        $inbound = $this->inbound('two-replies');
        (new ProcessChatbotResponseJob($inbound, [
            'messages' => [
                ['type' => 'text', 'content' => ['text' => 'Connecting you now.']],
                ['type' => 'text', 'content' => ['text' => 'Additional information.']],
            ],
            'actions' => [$this->handoverAction('explicit_human_agent_request')],
        ], 10, 'final'))->handle();

        $this->assertSame('1', Message::where('message_id', 'wamid.first')->firstOrFail()
            ->getMeta('handover_trigger'));
        $this->assertNull(Message::where('message_id', 'wamid.second')->firstOrFail()
            ->getMeta('handover_trigger'));
        Http::assertSentCount(2);
    }

    private function inbound(string $suffix): Message
    {
        $providerId = DB::table('channel_providers')->insertGetId([
            'uid' => (string) Str::ulid(), 'name' => 'WhatsApp',
            'small_name' => 'whatsapp', 'nature' => 'messaging',
        ]);
        $channel = Channel::create([
            'uid' => (string) Str::ulid(), 'user_id' => 1,
            'channel_provider_id' => $providerId, 'name' => 'Test WhatsApp',
        ]);
        $channel->setMeta('whatsapp_phone_number_id', 'phone-id');
        $channel->setMeta('system_user_token', 'test-token');
        $channel->setMeta('whatsapp_number', '911234567890');

        return Message::create([
            'channel_id' => $channel->id,
            'message_id' => 'wa-inbound-' . $suffix,
            'from' => '919999999999', 'to' => '911234567890',
            'message_type' => 'text', 'content' => 'Help me',
            'timestamp' => now(), 'status' => 'received',
            'created_by' => 0,
        ]);
    }

    private function handoverAction(string $reason): array
    {
        return [
            'id' => 'handover-queue',
            'data' => [
                ['key' => 'handover_reason', 'value' => $reason],
                ['key' => 'summary', 'value' => ['version' => 'v1', 'turns' => []]],
            ],
        ];
    }

    private function createInboxOwnership(Message $inbound, string $state, ?int $owner): void
    {
        if (!Schema::hasTable('operator_conversations')) {
            Schema::create('operator_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organisation_id');
                $table->unsignedBigInteger('channel_id');
                $table->string('participant_key');
                $table->string('handover_state');
                $table->unsignedBigInteger('owner_user_id')->nullable();
            });
        }
        DB::table('organisations')->insertOrIgnore(['id' => 1]);
        DB::table('model_has_organisations')->insert([
            'organisation_id' => 1, 'model_type' => Channel::class,
            'model_id' => $inbound->channel_id,
        ]);
        DB::table('operator_conversations')->insert([
            'organisation_id' => 1, 'channel_id' => $inbound->channel_id,
            'participant_key' => $inbound->from,
            'handover_state' => $state, 'owner_user_id' => $owner,
        ]);
    }
}
