<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\Enums\Module;
use Illuminate\Support\Str;

class MediaStorageService
{
    protected Channel $channel;
    protected array $config;

    public function __construct(Channel $channel)
    {
        $this->channel = $channel;
        $this->config = $this->getStorageConfig();
    }

    /**
     * Download and store media file
     */
    public function downloadAndStore(string $mediaId, string $type, array $messageData): ?array
    {
        try {
            Log::info('Starting media download and storage', [
                'media_id' => $mediaId,
                'type' => $type,
                'storage_driver' => $this->config['driver']
            ]);

            // Step 1: Get media info from WhatsApp API
            $mediaInfo = $this->getMediaInfo($mediaId);
            
            if (!$mediaInfo) {
                return null;
            }

            // Step 2: Download media content
            $mediaContent = $this->downloadMediaContent($mediaInfo['url']);
            
            if (!$mediaContent) {
                return null;
            }

            // Step 3: Optionally downgrade/compress media
            if ($this->shouldDowngrade($type)) {
                $mediaContent = $this->downgradeMedia($mediaContent, $type, $mediaInfo['mime_type']);
            }

            // Step 4: Store based on configuration
            $storedData = $this->storeMedia($mediaContent, $type, $mediaInfo, $messageData);

            if (!$storedData) {
                return null;
            }

            Log::info('Media stored successfully', [
                'media_id' => $mediaId,
                'driver' => $this->config['driver'],
                'path' => $storedData['path']
            ]);

            return $storedData;

        } catch (\Throwable $e) {
            Log::error('Media download and storage failed', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Get storage configuration
     */
    private function getStorageConfig(): array
    {
        $conf = ConfProvider::from(Module::SMART_MESSENGER);
        
        // Check if channel has override settings, otherwise use global config
        $driver = $this->channel->getMeta('media_storage_driver') ?? $conf->media_storage_driver;
        $downgradeEnabled = $this->channel->getMeta('media_downgrade_enabled') ?? $conf->media_downgrade_enabled;
        
        return [
            'driver' => $driver,
            'downgrade_enabled' => $downgradeEnabled,
            'downgrade_image_quality' => $this->channel->getMeta('media_downgrade_image_quality') ?? $conf->media_downgrade_image_quality,
            'downgrade_image_max_width' => $this->channel->getMeta('media_downgrade_image_max_width') ?? $conf->media_downgrade_image_max_width,
            'downgrade_image_max_height' => $this->channel->getMeta('media_downgrade_image_max_height') ?? $conf->media_downgrade_image_max_height,
            // S3 configuration
            's3_bucket' => $this->channel->getMeta('media_s3_bucket') ?? $conf->media_s3_bucket,
            's3_region' => $this->channel->getMeta('media_s3_region') ?? $conf->media_s3_region,
            's3_access_key' => $this->channel->getMeta('media_s3_access_key') ?? $conf->media_s3_access_key,
            's3_secret_key' => $this->channel->getMeta('media_s3_secret_key') ?? $conf->media_s3_secret_key,
            // Cloudinary configuration
            'cloudinary_cloud_name' => $this->channel->getMeta('media_cloudinary_cloud_name') ?? $conf->media_cloudinary_cloud_name,
            'cloudinary_api_key' => $this->channel->getMeta('media_cloudinary_api_key') ?? $conf->media_cloudinary_api_key,
            'cloudinary_api_secret' => $this->channel->getMeta('media_cloudinary_api_secret') ?? $conf->media_cloudinary_api_secret,
        ];
    }

    /**
     * Get media information from WhatsApp API
     */
    private function getMediaInfo(string $mediaId): ?array
    {
        $accessToken = $this->channel->getMeta('system_user_token');
        
        if (!$accessToken) {
            Log::error('No access token found', ['channel_id' => $this->channel->id]);
            return null;
        }

        $response = Http::withToken($accessToken)
            ->get("https://graph.facebook.com/v18.0/{$mediaId}");

        if (!$response->successful()) {
            Log::error('Failed to get media info', [
                'media_id' => $mediaId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            return null;
        }

        $data = $response->json();

        return [
            'url' => $data['url'] ?? null,
            'mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            'file_size' => $data['file_size'] ?? 0,
            'sha256' => $data['sha256'] ?? null,
        ];
    }

    /**
     * Download media content from WhatsApp
     */
    private function downloadMediaContent(string $url): ?string
    {
        $accessToken = $this->channel->getMeta('system_user_token');

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($url);

        if (!$response->successful()) {
            Log::error('Failed to download media content', [
                'url' => $url,
                'status' => $response->status()
            ]);
            return null;
        }

        return $response->body();
    }

    /**
     * Check if media should be downgraded
     */
    private function shouldDowngrade(string $type): bool
    {
        if (!$this->config['downgrade_enabled']) {
            return false;
        }

        // Only downgrade images for now
        return $type === 'image';
    }

    /**
     * Downgrade/compress media
     */
    private function downgradeMedia(string $content, string $type, string $mimeType): string
    {
        if ($type === 'image') {
            return $this->downgradeImage($content);
        }

        // Future: Add video, audio compression here
        return $content;
    }

    /**
     * Downgrade image quality and dimensions
     */
    private function downgradeImage(string $content): string
    {
        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($content);

            $maxWidth  = $this->config['downgrade_image_max_width'];
            $maxHeight = $this->config['downgrade_image_max_height'];
            $quality   = $this->config['downgrade_image_quality'];

            // v3-safe resize (immutable)
            if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
                $image = $image->scaleDown($maxWidth, $maxHeight);
            }

            // v3-safe encode
            $compressed = (string) $image->toJpeg($quality);

            $originalSize   = strlen($content);
            $compressedSize = strlen($compressed);
            $savedPercentage = round(
                (($originalSize - $compressedSize) / $originalSize) * 100,
                2
            );

            Log::info('Image compressed', [
                'original_size'   => $originalSize,
                'compressed_size' => $compressedSize,
                'saved_percentage'=> $savedPercentage,
                'quality'         => $quality
            ]);

            // 🔒 GUARD: don't return larger images
            if ($compressedSize >= $originalSize) {
                Log::info('Image compression skipped (larger than original)', [
                    'original_size'   => $originalSize,
                    'compressed_size' => $compressedSize,
                ]);

                return $content;
            }

            return $compressed;

        } catch (\Throwable $e) {
            // 🔒 LOG KEPT AS REQUESTED
            Log::warning('Image downgrade failed, using original', [
                'error' => $e->getMessage()
            ]);

            return $content;
        }
    }

    /**
     * Store media based on driver configuration
     */
    private function storeMedia(string $content, string $type, array $mediaInfo, array $messageData): ?array
    {
        return match($this->config['driver']) {
            'local' => $this->storeLocal($content, $type, $mediaInfo, $messageData),
            's3' => $this->storeS3($content, $type, $mediaInfo, $messageData),
            'cloudinary' => $this->storeCloudinary($content, $type, $mediaInfo, $messageData),
            default => $this->storeLocal($content, $type, $mediaInfo, $messageData),
        };
    }

    /**
     * Store media in local storage
     */
    private function storeLocal(string $content, string $type, array $mediaInfo, array $messageData): ?array
    {
        try {
            $extension = $this->getExtensionFromMimeType($mediaInfo['mime_type']);
            $filename = $this->generateFilename($type, $extension);
            $directory = "whatsapp/media/{$this->channel->uid}/" . date('Y/m');
            $path = "{$directory}/{$filename}";

            // Store file
            Storage::disk('public')->put($path, $content);

            // Generate public URL using asset() or config('app.url')
            $url = url('storage/' . $path);

            return [
                'driver' => 'local',
                'path' => $path,
                'url' => $url,
                'mime_type' => $mediaInfo['mime_type'],
                'size' => strlen($content),
                'original_size' => $mediaInfo['file_size'],
                'filename' => $messageData['filename'] ?? $filename,
            ];

        } catch (\Throwable $e) {
            Log::error('Local storage failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Store media in S3 (placeholder for future implementation)
     */
    private function storeS3(string $content, string $type, array $mediaInfo, array $messageData): ?array
    {
        // TODO: Implement S3 storage
        Log::warning('S3 storage not yet implemented, falling back to local');
        return $this->storeLocal($content, $type, $mediaInfo, $messageData);
    }

    /**
     * Store media in Cloudinary (placeholder for future implementation)
     */
    private function storeCloudinary(string $content, string $type, array $mediaInfo, array $messageData): ?array
    {
        // TODO: Implement Cloudinary storage
        Log::warning('Cloudinary storage not yet implemented, falling back to local');
        return $this->storeLocal($content, $type, $mediaInfo, $messageData);
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/amr' => 'amr',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }

    /**
     * Generate unique filename
     */
    private function generateFilename(string $type, string $extension): string
    {
        $uuid = Str::uuid();
        return "{$type}_{$uuid}.{$extension}";
    }
    
    /**
     * Download and store media from direct URL (non-WhatsApp)
     */
    public function downloadFromUrlAndStore(
        string $url,
        string $type,
        array $messageData = []
    ): ?array {
        try {
            Log::info('Starting URL media download and storage', [
                'url' => $url,
                'type' => $type,
            ]);

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::error('Failed to download media from URL', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $content = $response->body();
            $mimeType = $response->header('Content-Type') ?? 'application/octet-stream';

            // Optional downgrade
            if ($this->shouldDowngrade($type)) {
                $content = $this->downgradeMedia($content, $type, $mimeType);
            }

            return $this->storeMedia(
                $content,
                $type,
                [
                    'mime_type' => $mimeType,
                    'file_size' => strlen($content),
                    'sha256'    => null,
                ],
                $messageData
            );

        } catch (\Throwable $e) {
            Log::error('URL media storage failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

}