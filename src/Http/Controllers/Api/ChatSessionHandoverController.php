<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\SmartMessenger\Services\ChatSessionHandoverService;

class ChatSessionHandoverController extends Controller
{
    use Loggable;

    public function __construct(
        protected ChatSessionHandoverService $chatSessionHandoverService
    ) {
    }

    public function returnToBot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_uid' => 'required|string',
            'chatbot_integration_uid' => 'required|string',
            'agent_user_id' => 'nullable',
            'reason' => 'nullable|string',
        ]);

        $agentUserId = $validated['agent_user_id'] ?? auth()->id();
        $reason = $validated['reason'] ?? 'agent_returned_control_to_bot';
        $context = [
            'contact_uid' => $validated['contact_uid'],
            'chatbot_integration_uid' => $validated['chatbot_integration_uid'],
            'agent_user_id' => $agentUserId,
            'route_decision' => 'returned_to_bot',
        ];

        $this->logMethodStart('Handling return-to-bot API request' . $this->ctx($context));

        try {
            $result = $this->chatSessionHandoverService->returnControlToBot(
                $validated['contact_uid'],
                $validated['chatbot_integration_uid'],
                $agentUserId,
                $reason
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (ModelNotFoundException $e) {
            $this->logWarning('Return-to-bot failed because chat session was not found' . $this->ctx($context));

            return response()->json([
                'success' => false,
                'message' => 'Active chat session not found',
            ], 404);
        } catch (Throwable $e) {
            $this->logError('Return-to-bot API request failed' . $this->ctx($context + [
                'error' => $e->getMessage(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Failed to return control to chatbot',
            ], 500);
        } finally {
            $this->logMethodEnd('Return-to-bot API request complete' . $this->ctx($context));
        }
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
