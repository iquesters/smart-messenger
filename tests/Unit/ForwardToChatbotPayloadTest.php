<?php

namespace Iquesters\SmartMessenger\Tests\Unit;

use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ForwardToChatbotJob;

/**
 * MS-08: where the receiving channel identity comes from.
 *
 * A pure unit test against `preparePayload`, with no database and no
 * migrations. That is deliberate rather than convenient: the Feature tests in
 * this package boot the real migration set, and in this checkout 40 of the 86
 * tests already error on a migration-ordering problem
 * (`2025_02_04_000006_add_integration_id_to_messages_table` runs before
 * `messages` exists). A payload contract that only one broken environment can
 * verify is not verified, so the rule that matters is pinned here where it
 * runs.
 *
 * The rule: the channel uid comes from the stored message's own relation. It
 * is the trusted anchor for multi-store routing and for the restricted
 * single-channel release, so if the provider-supplied raw payload could set
 * it, a crafted webhook body would choose which store a conversation searches
 * - and with it which market's prices the customer is quoted.
 */
class ForwardToChatbotPayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_channel_uid_is_read_from_the_message_relation(): void
    {
        $payload = $this->preparePayload(
            channelUid: 'CHANNEL-FROM-DB',
            rawPayload: [],
        );

        $this->assertSame('CHANNEL-FROM-DB', $payload['receiving_channel_uid']);
    }

    public function test_the_raw_payload_cannot_set_the_channel_uid(): void
    {
        $payload = $this->preparePayload(
            channelUid: 'CHANNEL-FROM-DB',
            rawPayload: ['receiving_channel_uid' => 'ATTACKER-SUPPLIED'],
        );

        $this->assertSame('CHANNEL-FROM-DB', $payload['receiving_channel_uid']);
        $this->assertNotSame('ATTACKER-SUPPLIED', $payload['receiving_channel_uid']);
    }

    public function test_a_message_with_no_channel_sends_null_rather_than_a_guess(): void
    {
        // Null, not omitted and not defaulted. chatbot-core refuses a store
        // operation with no channel, and that refusal is correct: substituting
        // anything here would route the turn on an anchor nobody established.
        $payload = $this->preparePayload(channelUid: null, rawPayload: []);

        $this->assertArrayHasKey('receiving_channel_uid', $payload);
        $this->assertNull($payload['receiving_channel_uid']);
    }

    public function test_the_existing_identity_fields_still_travel(): void
    {
        // The new field is additive; nothing that worked before may stop.
        $payload = $this->preparePayload(channelUid: 'CHANNEL-1', rawPayload: []);

        $this->assertSame('chatbot-uid-1', $payload['integration_id']);
        $this->assertSame('contact-uid-1', $payload['contact_uid']);
        $this->assertSame('919812345678', $payload['contact_identifier']);
        $this->assertSame('wamid-1', $payload['message_id']);
        $this->assertSame('do you have red lehengas', $payload['message']);
    }

    public function test_the_contact_name_still_falls_back_to_the_raw_payload(): void
    {
        $payload = $this->preparePayload(
            channelUid: 'CHANNEL-1',
            rawPayload: ['contact_name' => 'From Webhook'],
            contactName: null,
        );

        $this->assertSame('From Webhook', $payload['contact_name']);
    }

    /**
     * Build the job with stubbed collaborators and call `preparePayload`.
     *
     * Reflection, because the method is private and should stay private - the
     * payload shape is an internal detail of this job, and widening its
     * visibility to make it testable would invite another caller.
     */
    private function preparePayload(
        ?string $channelUid,
        array $rawPayload,
        ?string $contactName = 'Customer One',
    ): array {
        // Partial mocks of the real classes, because `$message` and
        // `$contact` are typed properties on the job - a plain stub cannot be
        // assigned to them. The attributes are set through Eloquent's own
        // `__set`, so reading them back takes the normal attribute path and
        // never touches a relation query.
        $channel = null;
        if ($channelUid !== null) {
            $channel = Mockery::mock(Channel::class)->makePartial();
            $channel->uid = $channelUid;
        }

        $message = Mockery::mock(Message::class)->makePartial();
        $message->setRelation('channel', $channel);
        $message->message_id = 'wamid-1';
        $message->message_type = 'text';
        $message->content = 'do you have red lehengas';
        $message->from = '919812345678';
        $message->id = 1;
        $message->shouldReceive('getMeta')->with('media_url')->andReturn(null);

        $contact = Mockery::mock(Contact::class)->makePartial();
        $contact->uid = 'contact-uid-1';
        $contact->identifier = '919812345678';
        $contact->name = $contactName;

        $job = Mockery::mock(ForwardToChatbotJob::class)->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('logInfo')->andReturnNull();
        $job->shouldReceive('ctx')->andReturn('');

        foreach ([
            'message' => $message,
            'rawPayload' => $rawPayload,
            'contact' => $contact,
        ] as $property => $value) {
            $reflected = new \ReflectionProperty(ForwardToChatbotJob::class, $property);
            $reflected->setAccessible(true);
            $reflected->setValue($job, $value);
        }

        $method = new ReflectionMethod(ForwardToChatbotJob::class, 'preparePayload');
        $method->setAccessible(true);

        return $method->invoke($job, 'chatbot-uid-1');
    }
}
