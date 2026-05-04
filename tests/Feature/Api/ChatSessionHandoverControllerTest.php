<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Iquesters\SmartMessenger\Tests\TestCase;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;

class ChatSessionHandoverControllerTest extends TestCase
{
    use CreatesChatSessionsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createChatSessionsTable();
    }

    /** @test */
    public function it_returns_the_chat_session_to_bot_through_the_api_endpoint(): void
    {
        $this->withoutMiddleware();

        DB::table('chat_sessions')->insert([
            'session_id' => 'session-api',
            'contact_uid' => 'contact-api',
            'integration_id' => 'chatbot-api-uid',
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

        $response = $this->postJson('/api/smart-messenger/chat-sessions/handover/return-to-bot', [
            'contact_uid' => 'contact-api',
            'chatbot_integration_uid' => 'chatbot-api-uid',
            'agent_user_id' => 42,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'session-api')
            ->assertJsonPath('data.agent_user_id', 42)
            ->assertJsonPath('data.route_decision', 'returned_to_bot');
    }

    /** @test */
    public function it_returns_not_found_when_the_active_session_is_missing(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/smart-messenger/chat-sessions/handover/return-to-bot', [
            'contact_uid' => 'missing-contact',
            'chatbot_integration_uid' => 'missing-integration',
            'agent_user_id' => 42,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
