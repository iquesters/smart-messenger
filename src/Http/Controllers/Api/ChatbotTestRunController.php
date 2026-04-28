<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\SmartMessenger\Jobs\MessageJobs\RunChatbotTestBatchJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Services\ChatbotTestEntityService;

class ChatbotTestRunController extends Controller
{
    public function __construct(protected ChatbotTestEntityService $entityService)
    {
    }

    public function start(Request $request): JsonResponse
    {
        try {
            $this->entityService->assertRunnerSchemaReady();

            $data = $request->validate([
                'channel_id' => ['required', 'integer', 'exists:channels,id'],
                'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
                'case_uids' => ['nullable', 'array'],
                'case_uids.*' => ['string'],
            ]);

            $channel = Channel::find($data['channel_id']);
            if (!$channel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected channel was not found.',
                ], 404);
            }

            $cases = $this->entityService->getActiveCases($data['case_uids'] ?? []);
            if ($cases->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active chatbot test cases matched the request.',
                ], 422);
            }

            $run = $this->entityService->createRun(
                (int) $channel->id,
                (int) (auth()->id() ?? 0),
                (int) ($data['interval_minutes'] ?? 5),
                $cases
            );

            RunChatbotTestBatchJob::dispatch($run->run_uid);

            return response()->json([
                'success' => true,
                'message' => 'Chatbot test run started successfully.',
                'data' => $run,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $runUid): JsonResponse
    {
        try {
            $run = $this->entityService->getRunByRunUid($runUid);
            if (!$run) {
                return response()->json([
                    'success' => false,
                    'message' => 'Run not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'run' => $run,
                    'items' => $this->entityService->getRunItems($runUid)->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(string $runUid): JsonResponse
    {
        try {
            $this->entityService->cancelRun($runUid, (int) (auth()->id() ?? 0));

            return response()->json([
                'success' => true,
                'message' => 'Chatbot test run cancelled successfully.',
                'data' => $this->entityService->getRunByRunUid($runUid),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}