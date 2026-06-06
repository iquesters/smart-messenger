<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoConversionService
{
    private string $apiUrl;
    private string $watchFolder;
    private string $convertedFolder;
    private int $pollMaxAttempts;
    private int $pollIntervalMs;

    public function __construct()
    {
        $this->apiUrl = rtrim(env('CHATBOT_UTIL_API_URL', 'https://api-chatbot.iquesters.com/api'), '/');
        $this->watchFolder = env('VIDEO_WATCH_FOLDER', base_path('videos/raw'));
        $this->convertedFolder = env('VIDEO_CONVERTED_FOLDER', base_path('videos/processed'));
        $this->pollMaxAttempts = (int) env('VIDEO_CONVERSION_POLL_MAX_ATTEMPTS', 60);
        $this->pollIntervalMs = (int) env('VIDEO_CONVERSION_POLL_INTERVAL_MS', 2000);
    }

    public function submit(string $jobId, string $sourcePath): void
    {
        $destPath = $this->watchFolder . '/' . $jobId . '.mp4';
        $processedPath = $this->convertedFolder . '/' . $jobId . '.mp4';

        if (!is_dir($this->watchFolder)) {
            if (!mkdir($this->watchFolder, 0755, true) && !is_dir($this->watchFolder)) {
                throw new \RuntimeException("Cannot create watch folder: {$this->watchFolder}");
            }
        }

        if (!is_dir($this->convertedFolder)) {
            if (!mkdir($this->convertedFolder, 0755, true) && !is_dir($this->convertedFolder)) {
                throw new \RuntimeException("Cannot create converted folder: {$this->convertedFolder}");
            }
        }

        if (!copy($sourcePath, $destPath)) {
            throw new \RuntimeException("Failed to copy video to watch folder: {$destPath}");
        }

        Log::info('video_conversion.submitted', [
            'job_id' => $jobId,
            'source' => $sourcePath,
            'dest'   => $destPath,
        ]);
    }
    public function poll(string $jobId): array
    {
        $convertedPath = $this->convertedFolder . '/' . $jobId . '.mp4';

        for ($i = 0; $i < $this->pollMaxAttempts; $i++) {
            if (file_exists($convertedPath)) {
                Log::info('video_conversion.completed', [
                    'job_id'   => $jobId,
                    'path'     => $convertedPath,
                    'progress' => 100,
                ]);

                return [
                    'path'     => $convertedPath,
                    'progress' => 100,
                ];
            }

            Log::info('video_conversion.polling', [
                'job_id'  => $jobId,
                'attempt' => $i + 1,
            ]);

            usleep($this->pollIntervalMs * 1000);
        }

        throw new \RuntimeException('Video conversion timed out after ' . ($this->pollMaxAttempts * $this->pollIntervalMs / 1000) . 's');
    }

    public function generateJobId(): string
    {
        return Str::uuid()->toString();
    }
}