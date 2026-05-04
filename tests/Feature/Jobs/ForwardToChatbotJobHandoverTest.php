<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Jobs;

use Mockery;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ForwardToAgentJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ForwardToChatbotJob;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Tests\TestCase;

class ForwardToChatbotJobHandoverTest extends TestCase
{
    use CreatesChatSessionsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createChatSessionsTable();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** @test */
    public function active_handover_prevents_the_chatbot_core_call_and_marks_the_message(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake();

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid-1');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message, $contact] = $this->createInboundMessage('customer-1');

        DB::table('chat_sessions')->insert([
            'session_id' => 'session-active',
            'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid-1',
            'context_json' => json_encode([
                [
                    'ccx_state_snapshot' => [
                        'payload' => [
                            'human_handover' => [
                                'active' => true,
                                'since_utc' => '2026-05-02T18:30:00Z',
                                'reason' => 'explicit_human_agent_request',
                                'status' => 'active',
                            ],
                        ],
                    ],
                ],
            ]),
            'created_at' => now()->subMinute(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 1'], $contact));

        $message->refresh();

        Http::assertNothingSent();
        Bus::assertDispatched(ForwardToAgentJob::class);
        $this->assertSame('1', $message->getMeta('human_pending'));
        $this->assertSame('human_agent', $message->getMeta('human_route_decision'));
        $this->assertSame('session-active', $message->getMeta('chat_session_id'));
        $this->assertSame('explicit_human_agent_request', $message->getMeta('human_handover_reason'));
        $this->assertSame('2026-05-02T18:30:00Z', $message->getMeta('human_handover_time'));
    }

    /** @test */
    public function inactive_handover_continues_to_chatbot_core(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake([
            'https://chatbot.example.test/api/chat/v3' => Http::response(['accepted' => true], 200),
        ]);

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid-1');
        $resolver->shouldReceive('getApiToken')->once()->andReturn('token-123');
        $resolver->shouldReceive('getApiUrl')->once()->andReturn('https://chatbot.example.test/api/chat/v3');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message, $contact] = $this->createInboundMessage('customer-2');

        DB::table('chat_sessions')->insert([
            'session_id' => 'session-closed',
            'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid-1',
            'context_json' => json_encode([
                [
                    'ccx_state_snapshot' => [
                        'payload' => [
                            'human_handover' => [
                                'active' => false,
                                'since_utc' => '2026-05-02T18:30:00Z',
                                'ended_utc' => '2026-05-02T19:00:00Z',
                                'reason' => 'agent_returned_control_to_bot',
                                'status' => 'closed',
                            ],
                        ],
                    ],
                ],
            ]),
            'created_at' => now()->subMinute(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 2'], $contact));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://chatbot.example.test/api/chat/v3'
                && $request['integration_id'] === 'chatbot-uid-1';
        });
        Bus::assertNotDispatched(ForwardToAgentJob::class);
    }

    /** @test */
    public function missing_session_fails_open_to_chatbot_core(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake([
            'https://chatbot.example.test/api/chat/v3' => Http::response(['accepted' => true], 200),
        ]);

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid-1');
        $resolver->shouldReceive('getApiToken')->once()->andReturn(null);
        $resolver->shouldReceive('getApiUrl')->once()->andReturn('https://chatbot.example.test/api/chat/v3');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message, $contact] = $this->createInboundMessage('customer-3');

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 3'], $contact));

        Http::assertSentCount(1);
        Bus::assertNotDispatched(ForwardToAgentJob::class);
    }

    /** @test */
    public function malformed_context_json_fails_open_to_chatbot_core(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake([
            'https://chatbot.example.test/api/chat/v3' => Http::response(['accepted' => true], 200),
        ]);

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid-1');
        $resolver->shouldReceive('getApiToken')->once()->andReturn('token-123');
        $resolver->shouldReceive('getApiUrl')->once()->andReturn('https://chatbot.example.test/api/chat/v3');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message, $contact] = $this->createInboundMessage('customer-4');

        DB::table('chat_sessions')->insert([
            'session_id' => 'session-bad-json',
            'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid-1',
            'context_json' => '{"broken": ',
            'created_at' => now()->subMinute(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 4'], $contact));

        Http::assertSentCount(1);
        Bus::assertNotDispatched(ForwardToAgentJob::class);
    }

    /** @test */
    public function missing_contact_uid_fails_open_to_chatbot_core(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake([
            'https://chatbot.example.test/api/chat/v3' => Http::response(['accepted' => true], 200),
        ]);

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('chatbot-uid-1');
        $resolver->shouldReceive('getApiToken')->once()->andReturn('token-123');
        $resolver->shouldReceive('getApiUrl')->once()->andReturn('https://chatbot.example.test/api/chat/v3');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message] = $this->createInboundMessage('customer-5');

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 5'], null));

        Http::assertSentCount(1);
        Bus::assertNotDispatched(ForwardToAgentJob::class);
    }

    /** @test */
    public function missing_chatbot_integration_uid_fails_open_to_chatbot_core(): void
    {
        Bus::fake([ForwardToAgentJob::class]);
        Http::fake([
            'https://chatbot.example.test/api/chat/v3' => Http::response(['accepted' => true], 200),
        ]);

        $resolver = Mockery::mock(ChatbotIntegrationResolverService::class);
        $resolver->shouldReceive('resolveUidFromMessage')->once()->andReturn('');
        $resolver->shouldReceive('getApiToken')->once()->andReturn('token-123');
        $resolver->shouldReceive('getApiUrl')->once()->andReturn('https://chatbot.example.test/api/chat/v3');
        $this->app->instance(ChatbotIntegrationResolverService::class, $resolver);

        [$message, $contact] = $this->createInboundMessage('customer-6');

        Bus::dispatchSync(new ForwardToChatbotJob($message, ['contact_name' => 'Customer 6'], $contact));

        Http::assertSentCount(1);
        Bus::assertNotDispatched(ForwardToAgentJob::class);
    }

    private function createInboundMessage(string $suffix): array
    {
        $provider = DB::table('channel_providers')->insertGetId([
            'uid' => (string) Str::ulid(),
            'name' => 'WhatsApp',
            'small_name' => 'whatsapp',
            'nature' => 'messaging',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channel = Channel::create([
            'uid' => (string) Str::ulid(),
            'name' => 'Test Channel',
            'status' => 'active',
            'user_id' => 1,
            'channel_provider_id' => $provider,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $contact = Contact::create([
            'uid' => (string) Str::ulid(),
            'name' => "Customer {$suffix}",
            'identifier' => "customer-identifier-{$suffix}",
            'status' => 'active',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $message = Message::create([
            'channel_id' => $channel->id,
            'integration_id' => 999,
            'message_id' => "wa-message-{$suffix}",
            'from' => "91999999{$suffix}",
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'Hello bot',
            'timestamp' => now(),
            'status' => 'received',
            'raw_payload' => [],
            'raw_response' => [],
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        return [$message, $contact, $channel];
    }
}
