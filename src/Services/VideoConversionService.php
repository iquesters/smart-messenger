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
        $this->apiUrl = rtrim(env('CHATBOT_API_URL', 'https://api-chatbot.iquesters.com/api'), '/');
        $this->watchFolder = env('VIDEO_WATCH_FOLDER', base_path('videos'));
        $this->convertedFolder = env('VIDEO_CONVERTED_FOLDER', base_path('converted-videos'));
        $this->pollMaxAttempts = (int) env('VIDEO_CONVERSION_POLL_MAX_ATTEMPTS', 60);
        $this->pollIntervalMs = (int) env('VIDEO_CONVERSION_POLL_INTERVAL_MS', 2000);
    }

    public function submit(string $jobId, string $sourcePath): void
    {
        $destPath = $this->watchFolder . '/' . $jobId . '.mp4';

        if (!is_dir($this->watchFolder)) {
            if (!mkdir($this->watchFolder, 0755, true) && !is_dir($this->watchFolder)) {
                throw new \RuntimeException("Cannot create watch folder: {$this->watchFolder}");
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
        $url = "{$this->apiUrl}/media/jobs/{$jobId}";

        for ($i = 0; $i < $this->pollMaxAttempts; $i++) {
            $response = Http::timeout(5)->get($url);

            if ($response->failed()) {
                Log::warning('video_conversion.poll_failed', [
                    'job_id'  => $jobId,
                    'attempt' => $i + 1,
                    'status'  => $response->status(),
                ]);
                usleep($this->pollIntervalMs * 1000);
                continue;
            }

            $job = $response->json();

            if (($job['status'] ?? null) === 'completed') {
                $convertedPath = $this->convertedFolder . '/' . $jobId . '.mp4';

                if (!file_exists($convertedPath)) {
                    throw new \RuntimeException("Converted video not found: {$convertedPath}");
                }

                Log::info('video_conversion.completed', [
                    'job_id'   => $jobId,
                    'path'     => $convertedPath,
                    'progress' => $job['progress_pct'] ?? 100,
                ]);

                return [
                    'path'     => $convertedPath,
                    'progress' => $job['progress_pct'] ?? 100,
                ];
            }

            if (($job['status'] ?? null) === 'failed') {
                $error = $job['error'] ?? 'Unknown error';
                Log::error('video_conversion.failed', [
                    'job_id' => $jobId,
                    'error'  => $error,
                ]);
                throw new \RuntimeException("Video conversion failed: {$error}");
            }

            usleep($this->pollIntervalMs * 1000);
        }

        throw new \RuntimeException('Video conversion timed out after ' . ($this->pollMaxAttempts * $this->pollIntervalMs / 1000) . 's');
    }

    public function generateJobId(): string
    {
        return Str::uuid()->toString();
    }
}
