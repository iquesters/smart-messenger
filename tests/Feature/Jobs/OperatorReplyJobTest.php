<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Jobs;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob;
use Iquesters\SmartMessenger\Tests\Concerns\CreatesOperatorInboxTables;
use Iquesters\SmartMessenger\Tests\TestCase;

/**
 * Operator Inbox V9 human replies (L3).
 *
 * The job must never send a message itself - it re-validates and hands off
 * to the one existing WhatsApp sender - and must never send the same reply
 * twice, because a queue retry on a reply the customer already received is
 * the most visible failure this path has.
 */
class OperatorReplyJobTest extends TestCase
{
    use CreatesOperatorInboxTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOperatorOutboundCommandsTable();
    }

    /** @test */
    public function a_dispatched_command_is_handed_to_the_existing_whatsapp_sender(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);
        Http::fake();

        [$message] = $this->createInboundMessage('1');
        $uid = $this->createCommand($message->id, 'Your order ships today');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        // This job must not call Meta itself: there is exactly one sender.
        Http::assertNothingSent();
        Bus::assertDispatched(
            SendWhatsAppReplyJob::class,
            function ($job) use ($message) {
                $payload = $this->payloadOf($job);

                return $payload['type'] === 'text'
                    && $payload['text'] === 'Your order ships today'
                    && $payload['_actor'] === 'operator'
                    && (int) $payload['created_by_override'] === 7
                    && (int) $payload['_operator_anchor_inbound_message_id'] === $message->id;
            }
        );
    }

    /** @test */
    public function the_payload_carries_the_metas_the_projector_needs(): void
    {
        // Without `_actor` the projector classifies the outbound row as
        // `unknown`, which gives no Unanswered credit - so an answered
        // conversation would keep showing as waiting.
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message] = $this->createInboundMessage('2');
        $uid = $this->createCommand($message->id, 'On its way');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($job) use ($uid) {
            $payload = $this->payloadOf($job);

            return $payload['_operator_command_uid'] === $uid
                && array_key_exists('_operator_anchor_inbound_message_id', $payload);
        });
    }

    /** @test */
    public function an_already_sent_command_is_not_sent_again(): void
    {
        // A queue retry must not give the customer a second copy.
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message] = $this->createInboundMessage('3');
        $uid = $this->createCommand($message->id, 'Already gone', 'sent');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);
    }

    /** @test */
    public function a_command_already_linked_to_a_message_is_not_sent_again(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message] = $this->createInboundMessage('4');
        $uid = $this->createCommand($message->id, 'Linked');
        DB::table('operator_outbound_commands')
            ->where('uid', $uid)
            ->update(['message_id' => 4242]);

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);
    }

    /** @test */
    public function a_failed_command_is_not_retried_as_a_send(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message] = $this->createInboundMessage('5');
        $uid = $this->createCommand($message->id, 'Rejected', 'failed');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);
    }

    /** @test */
    public function a_missing_command_is_a_no_op(): void
    {
        // The transaction that should have written it rolled back. There is
        // nothing to send and nothing to repair.
        Bus::fake([SendWhatsAppReplyJob::class]);

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => '01JNOTACOMMANDATALL000000']));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);
    }

    /** @test */
    public function a_payload_without_a_command_uid_is_a_no_op(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);

        Bus::dispatchSync(new OperatorReplyJob([]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);
    }

    /** @test */
    public function a_reply_outside_the_whatsapp_window_is_marked_failed_not_sent(): void
    {
        // Meta would reject it. Recording the failure lets the inbox tell the
        // operator why, rather than showing a reply that never arrives.
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message] = $this->createInboundMessage('6');
        $message->timestamp = now()->subHours(30);
        $message->save();

        $uid = $this->createCommand($message->id, 'Too late');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);

        $command = DB::table('operator_outbound_commands')->where('uid', $uid)->first();
        $this->assertSame('failed', $command->state);
        $this->assertSame('reply_window_closed', $command->error_code);
    }

    /** @test */
    public function a_reply_on_an_inactive_channel_is_marked_failed_not_sent(): void
    {
        // The business turned the channel off between accepting the reply and
        // sending it.
        Bus::fake([SendWhatsAppReplyJob::class]);

        [$message, , $channel] = $this->createInboundMessage('7');
        $channel->status = 'inactive';
        $channel->save();

        $uid = $this->createCommand($message->id, 'Channel is off');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);

        $command = DB::table('operator_outbound_commands')->where('uid', $uid)->first();
        $this->assertSame('failed', $command->state);
        $this->assertSame('channel_inactive', $command->error_code);
    }

    /** @test */
    public function a_command_whose_anchor_message_is_gone_is_marked_failed(): void
    {
        Bus::fake([SendWhatsAppReplyJob::class]);

        $uid = $this->createCommand(999999, 'No anchor');

        Bus::dispatchSync(new OperatorReplyJob(['command_uid' => $uid]));

        Bus::assertNotDispatched(SendWhatsAppReplyJob::class);

        $command = DB::table('operator_outbound_commands')->where('uid', $uid)->first();
        $this->assertSame('failed', $command->state);
        $this->assertSame('anchor_message_missing', $command->error_code);
    }

    /**
     * Read the payload the sender was dispatched with.
     *
     * `SendWhatsAppReplyJob` keeps it in a protected property, which is what
     * the job under test actually sets, so it is read reflectively rather
     * than through an accessor that exists only for tests.
     */
    private function payloadOf($job): array
    {
        $reflection = new \ReflectionObject($job);
        $property = $reflection->getProperty('payload');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function createCommand(
        int $anchorMessageId,
        string $body,
        string $state = 'dispatched'
    ): string {
        $uid = (string) Str::ulid();

        DB::table('operator_outbound_commands')->insert([
            'uid' => $uid,
            'organisation_id' => 1,
            'conversation_id' => 101,
            'user_id' => 7,
            'client_message_id' => (string) Str::uuid(),
            'anchor_inbound_message_id' => $anchorMessageId,
            'message_type' => 'text',
            'body' => $body,
            'state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uid;
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
            'message_id' => "wa-operator-{$suffix}",
            'from' => "91999999{$suffix}",
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'Hello',
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
