<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Jobs;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ProcessChatbotResponseJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesChatSessionsTable;
use Iquesters\SmartMessenger\Tests\TestCase;

/**
 * The Operator Inbox classification contract on AI replies.
 *
 * Without `_actor` on the outbound payload the projector classifies an AI
 * reply as `unknown`, which gives no Unanswered credit - so a conversation
 * the AI already answered keeps showing as waiting. `_actor` also arms the
 * human-takeover suppression, which was unreachable while the key was
 * absent.
 *
 * Every outbound format is asserted separately on purpose: the defect was a
 * payload built per format, so a contract that holds for text and not for a
 * product image would reproduce it for exactly the messages that matter.
 */
class ProcessChatbotResponseJobActorTest extends TestCase
{
    use CreatesChatSessionsTable;

    /** @test */
    public function a_text_reply_carries_the_ai_actor_and_the_chatbot_integration(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('1');

        Bus::dispatchSync(new ProcessChatbotResponseJob(
            $message,
            ['messages' => [['type' => 'text', 'content' => ['text' => 'Here are 2 options.']]]],
            10,
            'final'
        ));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) {
            $payload = $this->payloadOf($job);

            return $payload['type'] === 'text'
                && $payload['_actor'] === 'ai'
                && $payload['_ai_kind'] === 'final'
                && (int) $payload['integration_id'] === 10;
        });
    }

    /** @test */
    public function a_progress_reply_is_marked_progress_so_it_cannot_give_credit(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('2');

        Bus::dispatchSync(new ProcessChatbotResponseJob(
            $message,
            ['messages' => [[
                'type' => 'text',
                'content' => ['text' => 'Please wait while I search the inventory for you.'],
            ]]],
            10,
            'progress'
        ));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) {
            $payload = $this->payloadOf($job);

            // Still an AI message - it is just not an answer.
            return $payload['_actor'] === 'ai'
                && $payload['_ai_kind'] === 'progress';
        });
    }

    /** @test */
    public function a_product_image_reply_carries_the_same_contract(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('3');

        Bus::dispatchSync(new ProcessChatbotResponseJob(
            $message,
            ['messages' => [[
                'type' => 'product',
                'content' => [
                    'title' => 'Brown Pure Silk Printed Saree',
                    'sku' => 'SSi2602734035',
                    'image_url' => 'https://example.test/saree.jpg',
                ],
            ]]],
            10,
            'final'
        ));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) {
            $payload = $this->payloadOf($job);

            return $payload['type'] === 'image'
                && $payload['_actor'] === 'ai'
                && (int) $payload['integration_id'] === 10;
        });
    }

    /** @test */
    public function a_product_without_an_image_still_carries_the_contract_as_text(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('4');

        Bus::dispatchSync(new ProcessChatbotResponseJob(
            $message,
            ['messages' => [[
                'type' => 'product',
                'content' => [
                    'title' => 'Soft Brown Pure Matka Baluchari Saree',
                    'sku' => 'SMa2601944061',
                ],
            ]]],
            10,
            'final'
        ));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) {
            $payload = $this->payloadOf($job);

            return $payload['type'] === 'text'
                && $payload['_actor'] === 'ai'
                && (int) $payload['integration_id'] === 10;
        });
    }

    /** @test */
    public function a_reply_enqueued_before_this_deploy_still_runs_without_a_kind(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('5');

        // Three arguments, as the previous dispatch signature passed. An
        // in-flight job must not fail on an undefined offset after deploy.
        Bus::dispatchSync(new ProcessChatbotResponseJob(
            $message,
            ['messages' => [['type' => 'text', 'content' => ['text' => 'Hello']]]],
            10
        ));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) {
            $payload = $this->payloadOf($job);

            return $payload['_actor'] === 'ai'
                && !array_key_exists('_ai_kind', $payload);
        });
    }

    /** @test */
    public function human_takeover_suppresses_the_ai_send(): void
    {
        Http::fake();
        $this->createChatSessionsTable();

        [$message, $contact] = $this->createInboundMessage('6');

        // Keyed by the Contact ULID, which is what ForwardToChatbotJob
        // writes. The resolver in SendWhatsAppReplyJob must reach it from
        // the inbound `from` identifier.
        DB::table('chat_sessions')->insert([
            'session_id' => 'session-taken-over',
            'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid-1',
            'context_json' => json_encode([[
                'ccx_state_snapshot' => ['payload' => ['human_handover' => [
                    'active' => true,
                    'since_utc' => '2026-11-06T18:30:00Z',
                    'reason' => 'explicit_human_agent_request',
                    'status' => 'active',
                ]]],
            ]]),
            'created_at' => now()->subMinute(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        Bus::dispatchSync(new SendWhatsAppReplyJob($message, [
            'type' => 'text',
            'text' => 'Here are 2 options.',
            'integration_id' => 10,
            '_actor' => 'ai',
            '_ai_kind' => 'final',
        ]));

        // Nothing reached Meta, and no outbound row was written.
        Http::assertNothingSent();
        $this->assertSame(
            0,
            Message::query()->where('channel_id', $message->channel_id)
                ->where('status', 'sent')->count()
        );
    }

    /** @test */
    public function an_operator_reply_is_not_suppressed_by_an_active_handover(): void
    {
        $this->createChatSessionsTable();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.operator']]], 200)]);

        [$message, $contact] = $this->createInboundMessage('7');

        DB::table('chat_sessions')->insert([
            'session_id' => 'session-owned',
            'contact_uid' => $contact->uid,
            'integration_id' => 'chatbot-uid-1',
            'context_json' => json_encode([[
                'ccx_state_snapshot' => ['payload' => ['human_handover' => [
                    'active' => true, 'status' => 'active',
                ]]],
            ]]),
            'created_at' => now()->subMinute(),
            'last_active_at' => now(),
            'expires_at' => null,
        ]);

        Bus::dispatchSync(new SendWhatsAppReplyJob($message, [
            'type' => 'text',
            'text' => 'I will take this from here.',
            '_actor' => 'operator',
            'created_by_override' => 7,
        ]));

        // The suppression is for AI replies only: the human who took the
        // conversation over must still be able to answer in it.
        Http::assertSentCount(1);
    }

    private function payloadOf($job): array
    {
        $reflection = new \ReflectionObject($job);
        $property = $reflection->getProperty('payload');
        $property->setAccessible(true);

        return $property->getValue($job);
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
            'identifier' => "91999999{$suffix}",
            'status' => 'active',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $message = Message::create([
            'channel_id' => $channel->id,
            'message_id' => "wa-inbound-{$suffix}",
            'from' => "91999999{$suffix}",
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'show me brown silk saree',
            'timestamp' => now(),
            'status' => 'received',
            'raw_payload' => [],
            'raw_response' => [],
            'created_by' => 0,
            'updated_by' => 0,
        ]);

        return [$message, $contact, $channel];
    }
}
