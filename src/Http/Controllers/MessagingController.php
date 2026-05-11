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
            'to'         => 'required|string',
            'message'    => 'nullable|string',
            'media'      => 'nullable|file|mimes:jpeg,png,mp4,3gp|max:102400',
        ]);

        $user = auth()->user();

        /**
         * ---------------------------------------------------------
         * Ensure user can access this channel
         * (user-owned OR organisation-owned)
         * ---------------------------------------------------------
         */
        $organisationIds = collect();

        if (method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        $profile = Channel::where('id', $request->profile_id)
            ->where(function ($query) use ($user, $organisationIds) {

                // User owned
                $query->where('created_by', $user->id);

                // Organisation owned
                if (
                    $organisationIds->isNotEmpty() &&
                    method_exists(Channel::class, 'organisations')
                ) {
                    $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with('metas')
            ->firstOrFail();

        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            return response()->json(['error' => 'WhatsApp credentials missing'], 422);
        }
        $hasMedia = $request->hasFile('media');
        $mediaType = null;
        $mediaUrl = null;

        if ($hasMedia) {
            $file = $request->file('media');
            $mime = $file->getMimeType();

            $path = $file->store('media/uploads', 'public');
            $mediaUrl = asset('storage/' . $path);

            if (in_array($mime, ['image/jpeg', 'image/png'])) {
                $mediaType = 'image';
            } elseif (in_array($mime, ['video/mp4', 'video/3gpp'])) {
                $mediaType = 'video';
            }
            if (!$mediaType) {
                return response()->json(['error' => 'Unsupported media type'], 422);
            }

            $whatsappMediaId = $this->uploadLocalMediaToWhatsApp($profile, $path, $mime);

            if (!$whatsappMediaId) {
                return response()->json(['error' => 'WhatsApp media upload failed'], 500);
            }
        }

        try {
            /**
             * 1️⃣ Send to WhatsApp
             */
            if ($hasMedia && $mediaType) {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $request->to,
                    'type'              => $mediaType,
                    $mediaType          => [
                        'id'      => $whatsappMediaId,
                        'caption' => $request->message ?? '',
                    ],
                ];
            } else {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $request->to,
                    'type'              => 'text',
                    'text'              => [
                        'body' => $request->message,
                    ],
                ];
            }

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                $payload
            );

            if (!$response->successful()) {
                Log::error('WhatsApp send failed', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);

                return response()->json(['error' => 'WhatsApp send failed'], 500);
            }
            $waMessageId = data_get($response->json(), 'messages.0.id');
            /**
             * 2️⃣ Save message locally
             */
            $message = Message::create([
            'channel_id'   => $profile->id,
            'message_id'   => $waMessageId,
            'from'         => ($profile->getMeta('country_code') ?? '') . $profile->getMeta('whatsapp_number'),
            'to'           => $request->to,
            'message_type' => $hasMedia ? $mediaType : 'text',
            'content'      => $hasMedia ? json_encode([
                'caption'           => $request->message ?? '',
                'media_url'         => $mediaUrl,
                'whatsapp_media_id' => $whatsappMediaId,
            ]) : $request->message,
            'timestamp'    => now(),
            'status'       => Constants::SENT,
            'raw_payload'  => $response->json(),
            'created_by'   => $user->id,
        ]);

        if ($hasMedia) {
            $message->setMeta('whatsapp_media_id', $whatsappMediaId);
            $message->setMeta('media_url', $mediaUrl);
            $message->setMeta('media_path', $path);
            $message->setMeta('media_mime_type', $mime);
            $message->setMeta('media_size', (string) $file->getSize());
        }

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
            ]);

            return response()->json([
                'status' => Constants::ERROR,
                'message' => 'Failed to send message',
            ], 500);
        }
    }
    private function uploadLocalMediaToWhatsApp(Channel $profile, string $path, string $mimeType): ?string
    {
        try {
            $absolutePath = storage_path('app/public/' . $path);

            if (!file_exists($absolutePath)) {
                Log::error('Media file not found for WhatsApp upload', [
                    'path' => $absolutePath,
                ]);
                return null;
            }

            $fileHandle = fopen($absolutePath, 'r');

            $response = Http::withToken($profile->getMeta('system_user_token'))
                ->attach(
                    'file',
                    $fileHandle,
                    basename($absolutePath)
                )
                ->post(
                    "https://graph.facebook.com/v18.0/" .
                    $profile->getMeta('whatsapp_phone_number_id') .
                    "/media",
                    [
                        'messaging_product' => 'whatsapp',
                        'type' => $mimeType,
                    ]
                );

            fclose($fileHandle);

            if (!$response->successful()) {
                Log::error('WhatsApp media upload failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                return null;
            }

            return $response->json('id');

        } catch (\Throwable $e) {
            Log::error('WhatsApp media upload exception', [
                'path' => $path,
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}