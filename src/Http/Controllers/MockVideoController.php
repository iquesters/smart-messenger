<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Constants\Constants;

class MockVideoController extends Controller
{
    public function normalizeVideo(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimetypes:video/mp4,video/3gpp',
            'message_id' => 'required|string',
            'quality' => 'nullable|string',
        ]);

        $messageId = $request->input('message_id');
        $file = $request->file('file');
        $filename = $messageId . '.mp4';

        $rawFolder = env('VIDEO_WATCH_FOLDER', base_path('videos/raw'));
        $processedFolder = env('VIDEO_CONVERTED_FOLDER', base_path('videos/processed'));

        // Copy to raw folder
        $file->move($rawFolder, $filename);

        // Auto copy to processed folder (simulating Python conversion)
        copy($rawFolder . '/' . $filename, $processedFolder . '/' . $filename);

        $job = [
            'message_id' => $messageId,
            'input_filename' => $file->getClientOriginalName(),
            'output_filename' => $filename,
            'output_path' => $processedFolder . '/' . $filename,
            'state' => 'ready',
            'progress_pct' => 100,
            'error_message' => null,
            'quality' => $request->input('quality'),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        Cache::put('mock-job-' . $messageId, $job, 3600);

        return response()->json($job);
    }

    public function jobStatus(string $messageId): JsonResponse
    {
        $job = Cache::get('mock-job-' . $messageId);

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json($job);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $messageId = $request->input('message_id');
        $channelId = $request->input('channel_id');
        $to = $request->input('to');
        $messageText = $request->input('message', '');
        
        if (!$messageId) {
            return response()->json(['error' => 'message_id is required'], 400);
        }

        if (!$channelId || !$to) {
            return response()->json(['error' => 'channel_id and to are required'], 400);
        }

        // Retrieve the cached job data from the normalize step
        $job = Cache::get('mock-job-' . $messageId);

        if (!$job) {
            return response()->json(['error' => 'Video job not found or expired'], 404);
        }

        // Verify the converted file exists
        $convertedPath = $job['output_path'];
        if (!file_exists($convertedPath)) {
            return response()->json(['error' => 'Converted video file not found'], 400);
        }

        // Get file size to show conversion happened
        $fileSize = filesize($convertedPath);

        // Copy the converted video into public storage for chat rendering
        $storageDir = storage_path('app/public/media/mock');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $publicFilename = $job['output_filename'];
        $storagePath = $storageDir . '/' . $publicFilename;
        copy($convertedPath, $storagePath);

        $mediaUrl = asset('storage/media/mock/' . $publicFilename);

        // Create the message record in the database
        try {
            $message = Message::create([
                'channel_id' => $channelId,
                'message_id' => 'mock-' . $messageId,
                'from' => Auth::user()?->email ?? 'mock-user',
                'to' => $to,
                'message_type' => 'video',
                'content' => json_encode([
                    'caption' => $messageText,
                    'media_url' => $mediaUrl,
                    'conversion_status' => 'completed',
                ]),
                'timestamp' => now(),
                'status' => Constants::SENT,
                'raw_payload' => [
                    'mock' => true,
                    'conversion_details' => [
                        'input_file' => $job['input_filename'],
                        'output_file' => $job['output_filename'],
                        'file_size' => $fileSize,
                    ],
                ],
                'created_by' => Auth::id() ?? 1,
            ]);

            if ($message) {
                $message->setMeta('media_url', $mediaUrl);
                $message->setMeta('media_path', 'media/mock/' . $publicFilename);
                $message->setMeta('media_mime_type', 'video/mp4');
                $message->setMeta('media_size', (string) $fileSize);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Mock message sent with video conversion',
                'message_id' => $message->id,
                'conversion' => [
                    'status' => 'completed',
                    'input_file' => $job['input_filename'],
                    'output_file' => $job['output_filename'],
                    'output_path' => $convertedPath,
                    'file_size_bytes' => $fileSize,
                    'progress_pct' => 100,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create message: ' . $e->getMessage()], 500);
        }
    }
}