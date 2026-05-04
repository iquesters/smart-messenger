<?php

namespace Iquesters\SmartMessenger\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Jobs\MessageJobs\NewMessageJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AgentReplyRoutingTest extends TestCase
{
    /** @test */
    public function it_builds_an_image_payload_when_an_agent_replies_with_an_image(): void
    {
        $channel = Channel::create([
            'uid' => 'CH-AGENT-IMG',
            'name' => 'Agent Image Channel',
            'status' => Constants::ACTIVE,
            'user_id' => 1,
            'channel_provider_id' => 1,
        ]);

        $originalMessage = Message::create([
            'channel_id' => $channel->id,
            'message_id' => 'customer-msg-001',
            'from' => '919999999999',
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'Need help',
            'timestamp' => now(),
            'status' => Constants::RECEIVED,
            'raw_payload' => [],
            'created_by' => 0,
        ]);

        $forwardedMessage = Message::create([
            'channel_id' => $channel->id,
            'message_id' => 'forwarded-msg-001',
            'from' => '911234567890',
            'to' => '918888877777',
            'message_type' => 'text',
            'content' => 'Forwarded message received',
            'timestamp' => now(),
            'status' => Constants::SENT,
            'raw_payload' => [],
            'created_by' => 0,
        ]);
        $forwardedMessage->setMeta('forwarded_from', $originalMessage->id);

        $savedMessage = Message::create([
            'channel_id' => $channel->id,
            'message_id' => 'agent-image-msg-001',
            'from' => '918888877777',
            'to' => '911234567890',
            'message_type' => 'image',
            'content' => json_encode([
                'caption' => 'Screenshot from agent',
                'mime_type' => 'image/png',
                'sha256' => '',
                'id' => 'incoming-image-id',
            ]),
            'timestamp' => now(),
            'status' => Constants::RECEIVED,
            'raw_payload' => [],
            'created_by' => 0,
        ]);
        $savedMessage->setMeta('media_driver', 'local');
        $savedMessage->setMeta('media_path', 'smart-messenger-tests/agent-image.png');
        $savedMessage->setMeta('media_url', 'http://localhost/storage/smart-messenger-tests/agent-image.png');
        $savedMessage->setMeta('media_mime_type', 'image/png');
        $savedMessage->setMeta('media_size', 1234);

        $rawPayload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'agent-image-msg-001',
                            'from' => '918888877777',
                            'type' => 'image',
                            'context' => ['id' => 'forwarded-msg-001'],
                        ]],
                        'metadata' => ['phone_number_id' => '12345'],
                    ],
                ]],
            ]],
        ];

        $job = new NewMessageJob(
            $channel,
            $rawPayload['entry'][0]['changes'][0]['value']['messages'][0],
            $rawPayload,
            $rawPayload['entry'][0]['changes'][0]['value']['metadata'],
            []
        );

        Bus::fake();

        $method = new ReflectionMethod(NewMessageJob::class, 'routeAgentReply');
        $method->setAccessible(true);
        $result = $method->invoke($job, $savedMessage);

        $this->assertTrue($result);

        Bus::assertDispatched(SendWhatsAppReplyJob::class, function ($dispatchedJob) use ($savedMessage, $originalMessage) {
            $reflection = new ReflectionClass($dispatchedJob);

            $payloadProperty = $reflection->getProperty('payload');
            $payloadProperty->setAccessible(true);
            $payload = $payloadProperty->getValue($dispatchedJob);

            $inboundProperty = $reflection->getProperty('inboundMessage');
            $inboundProperty->setAccessible(true);
            $inboundMessage = $inboundProperty->getValue($dispatchedJob);

            return $inboundMessage->is($savedMessage)
                && ($payload['type'] ?? null) === 'image'
                && ($payload['caption'] ?? null) === 'Screenshot from agent'
                && ($payload['to_override'] ?? null) === $originalMessage->from
                && ($payload['stored_media']['path'] ?? null) === 'smart-messenger-tests/agent-image.png'
                && ($payload['stored_media']['mime_type'] ?? null) === 'image/png';
        });
    }
}
