<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Services;

use Illuminate\Support\Facades\DB;
use Iquesters\SmartMessenger\Tests\TestCase;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Services\ChatSessionLookupService;

class ChatSessionLookupServiceTest extends TestCase
{
    use CreatesChatSessionsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createChatSessionsTable();
    }

    /** @test */
    public function it_returns_the_latest_active_session_for_contact_and_integration(): void
    {
        DB::table('chat_sessions')->insert([
            [
                'session_id' => 'session-old',
                'contact_uid' => 'contact-1',
                'integration_id' => 'chatbot-uid-1',
                'context_json' => '[]',
                'created_at' => now()->subMinutes(10),
                'last_active_at' => now()->subMinutes(5),
                'expires_at' => null,
            ],
            [
                'session_id' => 'session-new',
                'contact_uid' => 'contact-1',
                'integration_id' => 'chatbot-uid-1',
                'context_json' => '[]',
                'created_at' => now()->subMinutes(3),
                'last_active_at' => now()->subMinute(),
                'expires_at' => null,
            ],
        ]);

        $session = app(ChatSessionLookupService::class)->findLatestActive('contact-1', 'chatbot-uid-1');

        $this->assertNotNull($session);
        $this->assertSame('session-new', $session->session_id);
    }

    /** @test */
    public function it_ignores_expired_sessions(): void
    {
        DB::table('chat_sessions')->insert([
            [
                'session_id' => 'session-expired',
                'contact_uid' => 'contact-1',
                'integration_id' => 'chatbot-uid-1',
                'context_json' => '[]',
                'created_at' => now()->subMinutes(3),
                'last_active_at' => now()->subMinute(),
                'expires_at' => now()->subSecond(),
            ],
            [
                'session_id' => 'session-active',
                'contact_uid' => 'contact-1',
                'integration_id' => 'chatbot-uid-1',
                'context_json' => '[]',
                'created_at' => now()->subMinutes(4),
                'last_active_at' => now()->subMinutes(2),
                'expires_at' => now()->addHour(),
            ],
        ]);

        $session = app(ChatSessionLookupService::class)->findLatestActive('contact-1', 'chatbot-uid-1');

        $this->assertNotNull($session);
        $this->assertSame('session-active', $session->session_id);
    }

    /** @test */
    public function it_returns_null_when_no_session_exists(): void
    {
        $session = app(ChatSessionLookupService::class)->findLatestActive('missing-contact', 'missing-integration');

        $this->assertNull($session);
    }

    /** @test */
    public function it_uses_contact_uid_and_chatbot_integration_uid_for_lookup(): void
    {
        DB::table('chat_sessions')->insert([
            [
                'session_id' => 'numeric-like-session',
                'contact_uid' => 'contact-1',
                'integration_id' => '123',
                'context_json' => '[]',
                'created_at' => now()->subMinutes(2),
                'last_active_at' => now()->subMinute(),
                'expires_at' => null,
            ],
            [
                'session_id' => 'chatbot-uid-session',
                'contact_uid' => 'contact-1',
                'integration_id' => 'chatbot-uid-1',
                'context_json' => '[]',
                'created_at' => now()->subMinute(),
                'last_active_at' => now(),
                'expires_at' => null,
            ],
        ]);

        $session = app(ChatSessionLookupService::class)->findLatestActive('contact-1', 'chatbot-uid-1');

        $this->assertNotNull($session);
        $this->assertSame('chatbot-uid-session', $session->session_id);
        $this->assertNotSame('123', $session->integration_id);
    }
}
