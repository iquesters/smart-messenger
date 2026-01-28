<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

class ProcessChatbotResponseJob extends BaseJob
{
    protected Message $inboundMessage;
    protected array $chatbotResponse;

    protected function initialize(...$arguments): void
    {
        [$this->inboundMessage, $this->chatbotResponse] = $arguments;
    }

    /**
     * Main entry point
     */
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

    /* =====================================================
     | MESSAGE ROUTER
     |=====================================================*/
    private function routeMessage(array $message): void
    {
        $type = $message['type'] ?? 'unknown';

        Log::info('Routing message', [
            'type' => $type,
            'message_id' => $message['id'] ?? null
        ]);

        match ($type) {
            'product' => $this->handleProduct($message),
            'text'    => $this->handleText($message),
            default   => $this->handleUnknown($message),
        };
    }

    /* =====================================================
     | PRODUCT HANDLER
     |=====================================================*/
    private function handleProduct(array $message): void
    {
        Log::info('=== PRODUCT HANDLER STARTED ===', [
            'message' => $message
        ]);

        $content = $message['content'] ?? [];

        if (empty($content)) {
            Log::warning('Empty product content received', [
                'message' => $message
            ]);
            return;
        }

        $imageUrl = $content['image_url'] ?? null;
        
        Log::info('Product image URL check', [
            'has_image_url' => !empty($imageUrl),
            'image_url' => $imageUrl
        ]);

        // Download image locally if URL exists
        $imagePath = null;
        if ($imageUrl) {
            Log::info('Attempting to download image', ['url' => $imageUrl]);
            $imagePath = $this->downloadImage($imageUrl);
            Log::info('Image download result', [
                'success' => !empty($imagePath),
                'path' => $imagePath
            ]);
        }

        // Build full product caption with bullet separators for WhatsApp
        $whatsappCaption = $this->buildWhatsAppCaption($content);

        // Send IMAGE with full product details in caption
        if ($imagePath && $imageUrl && !empty($whatsappCaption)) {
            Log::info('Sending image with full product details', [
                'image_url' => $imageUrl,
                'image_path' => $imagePath,
                'caption' => $whatsappCaption
            ]);

            SendWhatsAppReplyJob::dispatchSync(
                $this->inboundMessage,
                [
                    'type' => 'image',
                    'image_url' => $imageUrl,
                    'image_path' => $imagePath,
                    'caption' => $whatsappCaption,
                    // Pass original content for database storage
                    'product_content' => $content,
                ]
            );

            Log::info('Image with product details sent');

            // Delete the image file after sending
            if ($imagePath && file_exists($imagePath)) {
                $deleted = @unlink($imagePath);
                Log::info('Image file deletion', [
                    'path' => $imagePath,
                    'deleted' => $deleted
                ]);
            }
        } else {
            // Fallback: if no image, send as text only
            Log::warning('No image available, sending product details as text', [
                'has_image_path' => !empty($imagePath),
                'has_image_url' => !empty($imageUrl)
            ]);

            $productText = $this->buildProductText($content);
            
            if (!empty($productText)) {
                SendWhatsAppReplyJob::dispatchSync(
                    $this->inboundMessage,
                    [
                        'type' => 'text',
                        'text' => $productText,
                    ]
                );
            }

            // Clean up image even if not sent
            if ($imagePath && file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        Log::info('=== PRODUCT HANDLER COMPLETED ===', [
            'product_id' => $content['product_id'] ?? null,
            'had_image'  => (bool) $imagePath,
            'image_deleted' => $imagePath && !file_exists($imagePath)
        ]);
    }

    /**
     * Build caption for WhatsApp with bullet separators
     * Format: Product Name • SKU: SKU123 • Price: 100 USD • https://...
     */
    private function buildWhatsAppCaption(array $product): string
    {
        $parts = [];

        // Add title
        if (!empty($product['title'])) {
            $parts[] = $product['title'];
        }

        // Add SKU (after title)
        if (!empty($product['sku'])) {
            $parts[] = 'SKU: ' . $product['sku'];
        }

        // Add price
        if (isset($product['price'])) {
            $parts[] = 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '');
        }

        // Add link
        if (!empty($product['link'])) {
            $parts[] = $product['link'];
        }

        // Join with bullet separator
        return implode(' • ', array_filter($parts));
    }

    /**
     * Build product text (fallback when no image)
     * Uses newlines for better text readability
     */
    private function buildProductText(array $product): string
    {
        $parts = [];

        if (!empty($product['title'])) {
            $parts[] = $product['title'];
        }

        if (!empty($product['sku'])) {
            $parts[] = 'SKU: ' . $product['sku'];
        }

        if (isset($product['price'])) {
            $parts[] = 'Price: ' . $product['price'] . ' ' . ($product['currency'] ?? '');
        }

        if (!empty($product['link'])) {
            $parts[] = $product['link'];
        }

        return implode("\n", array_filter($parts));
    }

    private function downloadImage(?string $url): ?string
    {
        if (!$url) {
            Log::info('downloadImage: No URL provided');
            return null;
        }

        try {
            Log::info('downloadImage: Starting download', ['url' => $url]);

            $response = Http::timeout(20)->get($url);

            if (!$response->successful()) {
                Log::warning('downloadImage: HTTP request failed', [
                    'url' => $url,
                    'status' => $response->status()
                ]);
                return null;
            }

            Log::info('downloadImage: HTTP request successful', [
                'content_length' => strlen($response->body()),
                'content_type' => $response->header('Content-Type')
            ]);

            $extension = pathinfo(
                parse_url($url, PHP_URL_PATH),
                PATHINFO_EXTENSION
            ) ?: 'jpg';

            $tmpDir = storage_path('app/tmp');
            
            if (!is_dir($tmpDir)) {
                Log::info('downloadImage: Creating tmp directory', ['path' => $tmpDir]);
                mkdir($tmpDir, 0755, true);
            }

            $filename = 'product_' . uniqid() . '.' . $extension;
            $path = $tmpDir . '/' . $filename;

            file_put_contents($path, $response->body());

            Log::info('downloadImage: File saved successfully', [
                'path' => $path,
                'size' => filesize($path),
                'exists' => file_exists($path)
            ]);

            return $path;

        } catch (\Throwable $e) {
            Log::error('downloadImage: Exception occurred', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /* =====================================================
     | TEXT HANDLER
     |=====================================================*/
    private function handleText(array $message): void
    {
        $content = $message['content'] ?? [];

        if (empty($content['text'])) {
            Log::warning('Empty text message received', [
                'message' => $message
            ]);
            return;
        }

        // Ensure payload includes 'type' and 'text'
        $payload = [
            'type' => 'text',
            'text' => $content['text'],
        ];

        // Use dispatchSync for immediate execution
        SendWhatsAppReplyJob::dispatchSync(
            $this->inboundMessage,
            $payload
        );

        Log::info('Text message handled', [
            'inbound_message_id' => $this->inboundMessage->id,
            'reply_to' => $message['reply_to'] ?? null
        ]);
    }

    /* =====================================================
     | UNKNOWN / FUTURE TYPES
     |=====================================================*/
    private function handleUnknown(array $message): void
    {
        Log::warning('Unknown chatbot message type', [
            'type' => $message['type'] ?? null,
            'payload' => $message,
            'inbound_message_id' => $this->inboundMessage->id
        ]);
    }
}