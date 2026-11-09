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
use Iquesters\SmartMessenger\Jobs\MessageJobs\ProcessChatbotResponseJob;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\SmartMessengerServiceProvider;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;
use Mockery;
use Orchestra\Testbench\TestCase;

/** Executable persistence contract, isolated from the package's misordered migration runner. */
class OperatorInboxHandoverMetadataContractTest extends TestCase
{
    use CreatesChatSessionsTable;

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
        $this->assertNull($outbound->getMeta('actor'));
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

    public function test_metadata_only_actor_does_not_arm_the_separate_takeover_suppression(): void
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
        $this->assertSame(1, Message::count());
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
}
