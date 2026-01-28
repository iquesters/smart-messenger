<?php

namespace Iquesters\SmartMessenger\Tests\Feature;

use Iquesters\SmartMessenger\Tests\TestCase;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Jobs\WhatsAppWHJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\ProcessChatbotResponseJob;
use Iquesters\SmartMessenger\Jobs\MessageJobs\SendWhatsAppReplyJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Iquesters\SmartMessenger\Events\MessageSentEvent;
use Illuminate\Support\Facades\Bus;

class WhatsAppJobFlowTest extends TestCase
{
    /** @test */
    public function it_processes_whatsapp_webhook_new_message()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wa-msg-123']]], 200),
            // Update to match your actual chatbot endpoint
            'localhost:8000/*' => Http::response([
                'session_id' => 'test-session-123',
                'directives' => [],
                'messages' => [
                    [
                        'id' => 'msg-001',
                        'type' => 'text',
                        'content' => ['text' => 'Reply from chatbot']
                    ]
                ]
            ], 200),
        ]);

        Event::fake();

        $channel = Channel::create([
            'uid' => 'CH123',
            'name' => 'Test WhatsApp Channel',
            'status' => Constants::ACTIVE,
            'user_id' => 1,
            'channel_provider_id' => 1,
        ]);
        $channel->setMeta('whatsapp_phone_number_id', '12345');
        $channel->setMeta('system_user_token', 'token-123');
        $channel->setMeta('country_code', '91');
        $channel->setMeta('whatsapp_number', '1234567890');

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'id' => 'msg_001',
                                        'from' => '919999999999',
                                        'text' => ['body' => 'Hello Bot'],
                                        'type' => 'text',
                                        'timestamp' => '1234567890'
                                    ]
                                ],
                                'metadata' => ['phone_number_id' => '12345'],
                                'contacts' => [['wa_id' => '919999999999', 'profile' => ['name' => 'Test User']]]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Bus::dispatchNow(new WhatsAppWHJob($payload, $channel->uid));

        $this->assertDatabaseHas('messages', ['from' => '919999999999']);

        // Assert chatbot API was called with correct endpoint
        Http::assertSent(fn($request) =>
            str_contains($request->url(), 'localhost:8000/api/test/chatbot')
        );

        // Assert reply was sent via WhatsApp
        Http::assertSent(fn($request) =>
            str_contains($request->url(), 'graph.facebook.com') &&
            str_contains($request->url(), '/messages')
        );

        Event::assertDispatched(MessageSentEvent::class, fn($event) =>
            $event->message->content === 'Reply from chatbot'
        );
    }

    /** @test */
    public function it_processes_chatbot_response_with_product_image_and_text()
    {
        // Use a counter to generate unique message IDs for each HTTP call
        $messageIdCounter = 0;
        
        Http::fake([
            'graph.facebook.com/*' => function () use (&$messageIdCounter) {
                $messageIdCounter++;
                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => '919999999999', 'wa_id' => '919999999999']],
                    'messages' => [['id' => 'wa-msg-' . time() . '-' . $messageIdCounter]]
                ], 200);
            },
            // Mock the product image URL
            'gigigadgets.com/*' => Http::response('fake-image-content', 200, [
                'Content-Type' => 'image/jpeg'
            ]),
        ]);

        Event::fake();

        $channel = Channel::create([
            'uid' => 'CH456',
            'name' => 'Test Channel 2',
            'status' => Constants::ACTIVE,
            'user_id' => 1,
            'channel_provider_id' => 1,
        ]);

        $channel->setMeta('whatsapp_phone_number_id', '12345');
        $channel->setMeta('system_user_token', 'token-123');
        $channel->setMeta('country_code', '91');
        $channel->setMeta('whatsapp_number', '1234567890');

        $message = Message::create([
            'channel_id' => $channel->id,
            'from' => '919999999999',
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'Hi Bot',
            'status' => Constants::RECEIVED,
            'timestamp' => now(),
            'created_by' => 0,
            'message_id' => 'msg-test-001',
        ]);

        $chatbotResponse = [
            'session_id' => 'test-session-456',
            'directives' => [],
            'messages' => [
                [
                    'id' => 'msg-001',
                    'type' => 'text',
                    'content' => ['text' => 'Hello from bot']
                ],
                [
                    'id' => 'msg-002',
                    'type' => 'product',
                    'content' => [
                        'title' => 'Test Product',
                        'sku' => 'SKU123',
                        'product_id' => 123,
                        'price' => 100,
                        'currency' => 'USD',
                        'link' => 'https://gigigadgets.com/product/test',
                        'image_url' => 'https://gigigadgets.com/image.jpg',
                        'caption' => null
                    ]
                ],
                [
                    'id' => 'msg-003',
                    'type' => 'text',
                    'reply_to' => 'msg-002',
                    'content' => ['text' => 'More info about product']
                ]
            ]
        ];

        Bus::dispatchNow(new ProcessChatbotResponseJob($message, $chatbotResponse));

        // Assert 3 new messages were created in database
        $sentMessages = Message::where('channel_id', $channel->id)
            ->where('status', Constants::SENT)
            ->where('id', '!=', $message->id)
            ->get();

        $this->assertCount(3, $sentMessages, 'Expected 3 messages to be created (text, image, text)');

        // Assert first text message
        $this->assertTrue(
            $sentMessages->contains('content', 'Hello from bot'),
            'First text message not found'
        );

        // Assert product image message exists
        $productMessage = $sentMessages->firstWhere('message_type', 'image');
        $this->assertNotNull($productMessage, 'Product image message not found');
        
        // Try to decode content as JSON
        $productContent = json_decode($productMessage->content, true);
        
        // If content is JSON with structured data
        if (is_array($productContent) && isset($productContent['caption'])) {
            // NEW FORMAT: Structured JSON with fields
            $this->assertArrayHasKey('caption', $productContent);
            
            // Check for bullet separators in caption
            $this->assertStringContainsString('Test Product', $productContent['caption']);
            $this->assertStringContainsString('SKU: SKU123', $productContent['caption']);
            $this->assertStringContainsString('Price: 100 USD', $productContent['caption']);
            $this->assertStringContainsString('•', $productContent['caption'], 'Caption should contain bullet separators');
            
            // Check structured fields if they exist
            if (isset($productContent['title'])) {
                $this->assertEquals('Test Product', $productContent['title']);
            }
            if (isset($productContent['sku'])) {
                $this->assertEquals('SKU123', $productContent['sku']);
            }
        } else {
            // OLD FORMAT: Check if content contains product info as text
            $content = $productMessage->content;
            $this->assertStringContainsString('Test Product', $content);
            $this->assertStringContainsString('SKU', $content);
            $this->assertStringContainsString('100', $content);
        }
        
        // Assert third text message
        $this->assertTrue(
            $sentMessages->contains('content', 'More info about product'),
            'Second text message not found'
        );

        // Assert WhatsApp API was called 3 times (once for each message)
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com') &&
                   str_contains($request->url(), '/messages');
        }, 3);

        // Assert image was downloaded
        Http::assertSent(fn($request) =>
            str_contains($request->url(), 'gigigadgets.com/image.jpg')
        );

        // Assert MessageSentEvent was dispatched 3 times
        Event::assertDispatched(MessageSentEvent::class, 3);
    }

    /** @test */
    public function send_whatsapp_reply_job_handles_empty_payload()
    {
        Http::fake();
        Event::fake();

        $channel = Channel::create([
            'uid' => 'CH789',
            'name' => 'Test Channel 3',
            'status' => Constants::ACTIVE,
            'user_id' => 1,
            'channel_provider_id' => 1,
        ]);

        $channel->setMeta('whatsapp_phone_number_id', '12345');
        $channel->setMeta('system_user_token', 'token-123');

        $message = Message::create([
            'channel_id' => $channel->id,
            'from' => '919999999999',
            'to' => '0987654321',
            'message_type' => 'text',
            'content' => 'Hello',
            'status' => Constants::RECEIVED,
            'timestamp' => now(),
            'created_by' => 0,
            'message_id' => 'msg-test-001',
        ]);

        $payload = [];

        Bus::dispatchNow(new SendWhatsAppReplyJob($message, $payload));

        // Should not send anything with empty payload
        Http::assertNothingSent();
        Event::assertNotDispatched(MessageSentEvent::class);
    }

    /** @test */
    public function it_sends_image_message_correctly()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '919999999999', 'wa_id' => '919999999999']],
                'messages' => [['id' => 'wa-msg-image-123']]
            ], 200),
        ]);

        Event::fake();

        $channel = Channel::create([
            'uid' => 'CH-IMAGE-TEST',
            'name' => 'Image Test Channel',
            'status' => Constants::ACTIVE,
            'user_id' => 1,
            'channel_provider_id' => 1,
        ]);

        $channel->setMeta('whatsapp_phone_number_id', '12345');
        $channel->setMeta('system_user_token', 'token-123');
        $channel->setMeta('country_code', '91');
        $channel->setMeta('whatsapp_number', '1234567890');

        $inboundMessage = Message::create([
            'channel_id' => $channel->id,
            'from' => '919999999999',
            'to' => '911234567890',
            'message_type' => 'text',
            'content' => 'Show me product',
            'status' => Constants::RECEIVED,
            'timestamp' => now(),
            'created_by' => 0,
            'message_id' => 'msg-inbound-001',
        ]);

        $payload = [
            'type' => 'image',
            'image_url' => 'https://example.com/product.png',
            'caption' => 'Product Name • SKU: ABC123 • Price: 50 USD • https://example.com/buy',
            'product_content' => [
                'title' => 'Product Name',
                'sku' => 'ABC123',
                'product_id' => 999,
                'price' => 50,
                'currency' => 'USD',
                'link' => 'https://example.com/buy'
            ]
        ];

        Bus::dispatchNow(new SendWhatsAppReplyJob($inboundMessage, $payload));

        // Assert message was created
        $sentMessage = Message::where('channel_id', $channel->id)
            ->where('status', Constants::SENT)
            ->where('message_type', 'image')
            ->first();

        $this->assertNotNull($sentMessage, 'Image message was not created');

        // Check content - works with both old and new formats
        $content = $sentMessage->content;
        
        // Try parsing as JSON
        $contentArray = json_decode($content, true);
        
        if (is_array($contentArray)) {
            // NEW FORMAT: JSON with structured data
            $this->assertArrayHasKey('caption', $contentArray);
            $this->assertStringContainsString('Product Name', $contentArray['caption']);
            $this->assertStringContainsString('•', $contentArray['caption']);
        } else {
            // OLD FORMAT: Just check caption exists
            $this->assertStringContainsString('Product Name', $content);
        }

        // Assert WhatsApp API was called correctly
        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), 'graph.facebook.com') &&
                   isset($body['type']) && $body['type'] === 'image' &&
                   isset($body['image']['link']) && $body['image']['link'] === 'https://example.com/product.png';
        });

        Event::assertDispatched(MessageSentEvent::class);
    }
}