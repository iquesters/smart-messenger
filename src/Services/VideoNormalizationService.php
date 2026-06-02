<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class VideoNormalizationService
{
    private float $timeoutSeconds;

    private int $maxWhatsAppSizeBytes;

    public function __construct(?float $timeoutSeconds = null)
    {
        $this->timeoutSeconds = $timeoutSeconds ?? (float) (env('VIDEO_CONVERSION_TIMEOUT_SECONDS', 120));
        $this->maxWhatsAppSizeBytes = 16 * 1024 * 1024;
    }

    public function isAvailable(): bool
    {
        return $this->binaryExists('ffmpeg') && $this->binaryExists('ffprobe');
    }

    public function getMaxWhatsAppSizeBytes(): int
    {
        return $this->maxWhatsAppSizeBytes;
    }

    public function probe(string $inputPath): ?array
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $inputPath,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('video_normalization.ffprobe_error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$process->isSuccessful()) {
            Log::warning('video_normalization.ffprobe_failed', [
                'exit_code' => $process->getExitCode(),
                'stderr'    => $process->getErrorOutput(),
            ]);

            return null;
        }

        $payload = json_decode($process->getOutput(), true);

        if (!is_array($payload)) {
            Log::warning('video_normalization.ffprobe_invalid_json');

            return null;
        }

        $streams = $payload['streams'] ?? [];
        $format  = $payload['format']  ?? [];

        $videoStream = null;
        $audioStream = null;

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            if (($stream['codec_type'] ?? '') === 'video' && $videoStream === null) {
                $videoStream = $stream;
            }

            if (($stream['codec_type'] ?? '') === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        $metadata = [
            'format_name'      => $this->optionalString($format['format_name'] ?? null),
            'duration_seconds' => $this->optionalFloat($format['duration'] ?? null),
            'video_codec'      => $this->optionalString($videoStream['codec_name'] ?? null),
            'audio_codec'      => $this->optionalString($audioStream['codec_name'] ?? null),
            'width'            => $this->optionalInt($videoStream['width'] ?? null),
            'height'           => $this->optionalInt($videoStream['height'] ?? null),
            'fps'              => $this->parseFps($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? null),
        ];

        Log::info('video_normalization.input_metadata', $metadata);

        return $metadata;
    }

    public function normalize(string $inputPath): VideoNormalizationResult
    {
        $this->assertInputValid($inputPath);

        $tempDir = sys_get_temp_dir() . '/video-normalize-' . uniqid();

        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            throw new VideoNormalizationException(
                'Failed to create temporary directory for video processing',
                'temp_dir_failed'
            );
        }

        $outputPath = $tempDir . '/converted.mp4';

        try {
            $this->probe($inputPath);
            $this->convert($inputPath, $outputPath);
            $this->assertOutputValid($outputPath);

            Log::info('video_normalization.complete', [
                'input_size_bytes'  => filesize($inputPath),
                'output_size_bytes' => filesize($outputPath),
            ]);

            return new VideoNormalizationResult(
                outputPath: $outputPath,
                mimeType: 'video/mp4',
                tempDir: $tempDir,
            );
        } catch (VideoNormalizationException $e) {
            $this->cleanupTempDir($tempDir);
            throw $e;
        } catch (\Throwable $e) {
            $this->cleanupTempDir($tempDir);
            throw new VideoNormalizationException(
                'Video processing failed: ' . $e->getMessage(),
                'normalization_failed',
                $e
            );
        }
    }

    public function convert(string $inputPath, string $outputPath): void
    {
        $process = new Process([
            'ffmpeg',
            '-y',
            '-i', $inputPath,
            '-map', '0:v:0',
            '-map', '0:a?',
            '-vf', "scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease,fps=30,format=yuv420p",
            '-c:v', 'libx264',
            '-profile:v', 'baseline',
            '-level', '3.0',
            '-preset', 'veryfast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            Log::error('video_normalization.ffmpeg_timeout', [
                'timeout_seconds' => $this->timeoutSeconds,
            ]);
            throw new VideoNormalizationException(
                'Video conversion timed out after ' . $this->timeoutSeconds . ' seconds',
                'conversion_timeout',
                $e
            );
        } catch (\Throwable $e) {
            Log::error('video_normalization.ffmpeg_error', [
                'error' => $e->getMessage(),
            ]);
            throw new VideoNormalizationException(
                'Video conversion failed: ' . $e->getMessage(),
                'conversion_failed',
                $e
            );
        }

        if (!$process->isSuccessful()) {
            Log::error('video_normalization.ffmpeg_failed', [
                'exit_code' => $process->getExitCode(),
                'stderr'    => $process->getErrorOutput(),
            ]);
            throw new VideoNormalizationException(
                'Video codec conversion failed',
                'conversion_failed'
            );
        }

        Log::info('video_normalization.ffmpeg_complete', [
            'output_path' => $outputPath,
        ]);
    }

    public function cleanupTempDir(string $tempDir): void
    {
        if (!is_dir($tempDir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $path = $file->getRealPath();

                if ($file->isDir()) {
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }

            rmdir($tempDir);
        } catch (\Throwable $e) {
            Log::warning('video_normalization.cleanup_failed', [
                'temp_dir' => $tempDir,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function assertInputValid(string $inputPath): void
    {
        if (!file_exists($inputPath)) {
            throw new VideoNormalizationException(
                'Video file not found',
                'file_not_found'
            );
        }

        if (filesize($inputPath) <= 0) {
            throw new VideoNormalizationException(
                'Video file is empty',
                'file_empty'
            );
        }
    }

    private function assertOutputValid(string $outputPath): void
    {
        if (!file_exists($outputPath)) {
            throw new VideoNormalizationException(
                'Converted video file is missing',
                'output_missing'
            );
        }

        if (filesize($outputPath) <= 0) {
            throw new VideoNormalizationException(
                'Converted video file is empty',
                'output_empty'
            );
        }

        if (filesize($outputPath) > $this->maxWhatsAppSizeBytes) {
            throw new VideoNormalizationException(
                'Video exceeds WhatsApp 16 MB limit even after compression',
                'output_too_large'
            );
        }
    }

    private function binaryExists(string $name): bool
    {
        $result = trim(shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null') ?? '');

        return $result !== '';
    }

    private function optionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function optionalFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function parseFps(mixed $value): ?float
    {
        $normalized = $this->optionalString($value);

        if ($normalized === null || $normalized === '0/0') {
            return null;
        }

        if (!str_contains($normalized, '/')) {
            return $this->optionalFloat($normalized);
        }

        [$numerator, $denominator] = explode('/', $normalized, 2);
        $den = (float) $denominator;

        if ($den === 0.0) {
            return null;
        }

        return (float) $numerator / $den;
    }
}
