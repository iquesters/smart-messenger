<?php

namespace Iquesters\SmartMessenger\Tests\Unit\Services;

use Iquesters\SmartMessenger\Services\VideoNormalizationService;
use Iquesters\SmartMessenger\Services\VideoNormalizationException;
use Iquesters\SmartMessenger\Services\VideoNormalizationResult;
use Iquesters\SmartMessenger\Tests\TestCase;

class VideoNormalizationServiceTest extends TestCase
{
    private VideoNormalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VideoNormalizationService();
    }

    /** @test */
    public function is_available_detects_ffmpeg_and_ffprobe(): void
    {
        $available = $this->service->isAvailable();

        $this->assertTrue($available, 'FFmpeg and FFprobe should be available on this system');
    }

    /** @test */
    public function get_max_whatsapp_size_bytes_returns_16_mb(): void
    {
        $this->assertSame(16 * 1024 * 1024, $this->service->getMaxWhatsAppSizeBytes());
    }

    /** @test */
    public function normalize_throws_exception_for_nonexistent_file(): void
    {
        $this->expectException(VideoNormalizationException::class);
        $this->expectExceptionMessage('Video file not found');

        $this->service->normalize('/tmp/nonexistent-video-file-' . uniqid() . '.mp4');
    }

    /** @test */
    public function normalize_throws_exception_for_empty_file(): void
    {
        $emptyFile = sys_get_temp_dir() . '/empty-video-' . uniqid() . '.mp4';
        file_put_contents($emptyFile, '');

        try {
            $this->expectException(VideoNormalizationException::class);
            $this->expectExceptionMessage('Video file is empty');

            $this->service->normalize($emptyFile);
        } finally {
            @unlink($emptyFile);
        }
    }

    /** @test */
    public function cleanup_temp_dir_removes_directory(): void
    {
        $tempDir = sys_get_temp_dir() . '/video-normalize-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.txt', 'content');

        $this->assertDirectoryExists($tempDir);

        $this->service->cleanupTempDir($tempDir);

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    /** @test */
    public function cleanup_temp_dir_handles_nonexistent_directory(): void
    {
        $this->service->cleanupTempDir('/tmp/nonexistent-dir-' . uniqid());

        $this->assertTrue(true);
    }

    /** @test */
    public function probe_returns_null_for_invalid_file(): void
    {
        $result = $this->service->probe('/tmp/nonexistent-probe-file-' . uniqid());

        $this->assertNull($result);
    }

    /** @test */
    public function normalize_returns_result_for_valid_video(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('FFmpeg not available');
        }

        $tempDir = sys_get_temp_dir() . '/video-normalize-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        $inputPath = $tempDir . '/test-input.mp4';

        $this->createTestVideo($inputPath);

        try {
            $result = $this->service->normalize($inputPath);

            $this->assertInstanceOf(VideoNormalizationResult::class, $result);
            $this->assertFileExists($result->outputPath);
            $this->assertSame('video/mp4', $result->mimeType);
            $this->assertGreaterThan(0, filesize($result->outputPath));
            $this->assertLessThanOrEqual(16 * 1024 * 1024, filesize($result->outputPath));

            $this->service->cleanupTempDir($result->tempDir);
        } finally {
            $this->service->cleanupTempDir($tempDir);
        }
    }

    /** @test */
    public function probe_returns_metadata_for_valid_video(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('FFprobe not available');
        }

        $tempDir = sys_get_temp_dir() . '/video-normalize-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        $inputPath = $tempDir . '/test-input.mp4';

        $this->createTestVideo($inputPath);

        try {
            $metadata = $this->service->probe($inputPath);

            $this->assertIsArray($metadata);
            $this->assertArrayHasKey('format_name', $metadata);
            $this->assertArrayHasKey('video_codec', $metadata);
            $this->assertArrayHasKey('width', $metadata);
            $this->assertArrayHasKey('height', $metadata);

            $this->assertNotNull($metadata['video_codec']);
            $this->assertNotNull($metadata['width']);
            $this->assertNotNull($metadata['height']);
        } finally {
            $this->service->cleanupTempDir($tempDir);
        }
    }

    /** @test */
    public function normalize_cleans_up_temp_dir_on_failure(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('FFmpeg not available');
        }

        $tempDir = sys_get_temp_dir() . '/video-normalize-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        $inputPath = $tempDir . '/test-input.mp4';

        $this->createTestVideo($inputPath);

        try {
            $result = $this->service->normalize($inputPath);
            $normalizedDir = $result->tempDir;

            $this->assertDirectoryExists($normalizedDir);

            $this->service->cleanupTempDir($normalizedDir);

            $this->assertDirectoryDoesNotExist($normalizedDir);
        } finally {
            $this->service->cleanupTempDir($tempDir);
        }
    }

    private function createTestVideo(string $outputPath): void
    {
        $process = new \Symfony\Component\Process\Process([
            'ffmpeg',
            '-y',
            '-f', 'lavfi',
            '-i', 'testsrc=duration=2:size=320x240:rate=30',
            '-f', 'lavfi',
            '-i', 'sine=frequency=440:duration=2',
            '-c:v', 'libx264',
            '-c:a', 'aac',
            '-shortest',
            '-t', '2',
            $outputPath,
        ]);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->markTestSkipped('Failed to create test video: ' . $process->getErrorOutput());
        }

        $this->assertFileExists($outputPath);
    }
}
