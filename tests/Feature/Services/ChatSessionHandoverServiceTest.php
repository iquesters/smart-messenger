<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Iquesters\SmartMessenger\Tests\TestCase;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Services\ChatSessionHandoverService;
use Iquesters\SmartMessenger\Services\HumanHandoverStateResolver;

class ChatSessionHandoverServiceTest extends TestCase
{
    use CreatesChatSessionsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createChatSessionsTable();
    }

    /** @test */
    public function it_appends_a_closed_handover_entry_without_overwriting_history(): void
    {
        DB::table('chat_sessions')->insert([
            'session_id' => 'session-1',
            'contact_uid' => 'contact-1',
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
            'created_at' => now()->subHour(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        $result = app(ChatSessionHandoverService::class)->returnControlToBot(
            'contact-1',
            'chatbot-uid-1',
            99
        );

        $session = DB::table('chat_sessions')->where('session_id', 'session-1')->first();
        $entries = json_decode($session->context_json, true);
        $latestState = app(HumanHandoverStateResolver::class)->resolve($entries);

        $this->assertSame('session-1', $result['session_id']);
        $this->assertCount(2, $entries);
        $this->assertFalse($latestState['active']);
        $this->assertSame('2026-05-02T18:30:00Z', $latestState['hand_over_time']);
        $this->assertSame('agent_returned_control_to_bot', $latestState['reason']);
        $this->assertSame('closed', $latestState['status']);
        $this->assertSame('agent', $latestState['ended_by']);
    }

    /** @test */
    public function malformed_context_json_is_safely_normalized_before_appending(): void
    {
        DB::table('chat_sessions')->insert([
            'session_id' => 'session-bad-json',
            'contact_uid' => 'contact-2',
            'integration_id' => 'chatbot-uid-2',
            'context_json' => '{"broken": ',
            'created_at' => now()->subHour(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        app(ChatSessionHandoverService::class)->returnControlToBot(
            'contact-2',
            'chatbot-uid-2',
            77
        );

        $session = DB::table('chat_sessions')->where('session_id', 'session-bad-json')->first();
        $entries = json_decode($session->context_json, true);
        $latestState = app(HumanHandoverStateResolver::class)->resolve($entries);

        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertFalse($latestState['active']);
        $this->assertSame('agent_returned_control_to_bot', $latestState['reason']);
    }

    /** @test */
    public function it_throws_a_controlled_error_when_the_session_is_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(ChatSessionHandoverService::class)->returnControlToBot(
            'missing-contact',
            'missing-integration',
            1
        );
    }
}
