<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Services\MessagingDataService;
use Iquesters\SmartMessenger\Services\MessagingSendService;

class MessagingController extends Controller
{
    public function __construct(
        protected MessagingDataService $messagingDataService,
        protected MessagingSendService $messagingSendService,
    ) {
    }

    public function index(Request $request)
    {
        $data = $this->messagingDataService->buildInboxData(auth()->user(), [
            'number' => $request->get('number'),
            'contact' => $request->get('contact'),
        ]);

        return view('smartmessenger::messages.index', $data);
    }

    public function loadOlderMessages(Request $request): JsonResponse
    {
        $request->validate([
            'profile_id' => 'required|integer|exists:channels,id',
            'contact' => 'required|string',
            'before_id' => 'required|integer|exists:messages,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth()->user();
        $profile = $this->messagingDataService->resolveAccessibleProfile((int) $request->input('profile_id'), $user);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found or access denied',
            ], 403);
        }

        $data = $this->messagingDataService->buildOlderMessagesData(
            $profile,
            (string) $request->input('contact'),
            (int) $request->input('before_id'),
            (int) $request->input('limit', 10),
            $user
        );

        if (!($data['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $data['message'] ?? 'Unable to load older messages',
            ], $data['status'] ?? 400);
        }

        $html = view('smartmessenger::messages.partials.chat.messages-list', [
            'messages' => $data['messages'],
            'selectedNumber' => $data['selectedNumber'],
            'isSuperAdmin' => $data['isSuperAdmin'],
            'integrationUid' => $data['integrationUid'],
        ])->render();

        return response()->json([
            'success' => true,
            'html' => $html,
            'has_more' => $data['has_more'],
            'oldest_id' => $data['oldest_id'],
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'profile_id' => 'required|exists:channels,id',
            'to' => 'required|string',
            'message' => 'nullable|string',
            'media' => 'nullable|file|mimes:jpeg,png,mp4,3gp|max:102400',
        ]);

        $user = auth()->user();
        $profile = $this->messagingDataService->resolveAccessibleProfile((int) $request->input('profile_id'), $user);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found or access denied',
            ], 403);
        }

        try {
            $message = $this->messagingSendService->sendMessage(
                $profile,
                (string) $request->input('to'),
                $request->input('message'),
                $request->file('media'),
                (int) $user->id
            );

            return response()->json([
                'status' => Constants::SUCCESS,
                'message' => $message,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Send message exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => Constants::ERROR,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ], 500);
        }
    }
}
