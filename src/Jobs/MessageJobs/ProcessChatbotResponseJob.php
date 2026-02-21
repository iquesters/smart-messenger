<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\MediaStorageService;

class ProcessChatbotResponseJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $chatbotResponse;
    protected ?int $integrationId;
    
    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->chatbotResponse, $this->integrationId] = $arguments;
    }

    public function process(): void
    {
        $messages = $this->chatbotResponse['messages'] ?? [];

        if (empty($messages)) {
            Log::info('Chatbot response has no messages', [
                'inbound_message_id' => $this->inboundMessage->id
            ]);
            return;
        }

        foreach ($messages as $message) {
            $this->routeMessage($message);
        }
    }

    private function routeMessage(array $message): void
    {
        $messageType = $message['type'] ?? 'unknown';
        
        match ($messageType) {
            'product' => $this->handleProduct($message),
            'text'    => $this->handleText($message),
            default   => $this->handleUnknown($message),
        };
        
        // 🔥 LONGER DELAY AFTER IMAGES (they need more processing time)
        if ($messageType === 'product' && isset($message['content']['image_url'])) {
            usleep(1000000); // 1 second for images
        } else {
            usleep(300000); // 300ms for text
        }
    }

    /**
     * ===========================
     * PRODUCT HANDLER
     * ===========================
     */
    private function handleProduct(array $message): void
    {
        $content = $message['content'] ?? [];
        if (empty($content)) {
            return;
        }

        $imageUrl = $content['image_url'] ?? null;
        $caption  = $this->buildWhatsAppCaption($content);

        /**
         * 🔥 STORE MEDIA LOCALLY (NEW SYSTEM)
         */
        $storedMedia = null;
        if ($imageUrl) {
            $mediaService = new MediaStorageService($this->inboundMessage->channel);

            $storedMedia = $mediaService->downloadFromUrlAndStore(
                $imageUrl,
                'image',
                ['filename' => 'product_image']
            );
        }
        /**
         * ✅ SEND USING ORIGINAL IMAGE URL (OLD WORKING WAY)
         * 🔥 CHANGED: dispatchSync → dispatch to prevent blocking
         */
        if ($imageUrl) {
            SendWhatsAppReplyJob::dispatchSync(  // ← KEEP dispatchSync for ordering
                $this->inboundMessage,
                [
                    'type'            => 'image',
                    'image_url'       => $imageUrl,
                    'caption'         => $caption,
                    'stored_media'    => $storedMedia,
                    'product_content' => $content,
                    'integration_id'  => $this->integrationId,
                ]
            );
            return;
        }

        /**
         * FALLBACK → TEXT
         */
        $text = $this->buildProductText($content);
        if ($text) {
            SendWhatsAppReplyJob::dispatchSync(
                $this->inboundMessage,
                [
                    'type' => 'text',
                    'text' => $text,
                    'integration_id' => $this->integrationId,
                ]
            );
        }
    }

    private function buildWhatsAppCaption(array $product): string
    {
        return implode(' • ', array_filter([
            $product['title'] ?? null,
            !empty($product['sku']) ? 'SKU: ' . $product['sku'] : null,
            isset($product['price']) ? 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '') : null,
            $product['link'] ?? null,
        ]));
    }

    private function buildProductText(array $product): string
    {
        return implode("\n", array_filter([
            $product['title'] ?? null,
            !empty($product['sku']) ? 'SKU: ' . $product['sku'] : null,
            isset($product['price']) ? 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '') : null,
            $product['link'] ?? null,
        ]));
    }

    private function handleText(array $message): void
    {
        $text = $message['content']['text'] ?? null;
        if (!$text) {
            return;
        }

        SendWhatsAppReplyJob::dispatchSync(
            $this->inboundMessage,
            [
                'type' => 'text',
                'text' => $text,
                'integration_id'  => $this->integrationId,
            ]
        );
    }

    private function handleUnknown(array $message): void
    {
        Log::warning('Unknown chatbot message type', [
            'payload' => $message
        ]);
    }
}