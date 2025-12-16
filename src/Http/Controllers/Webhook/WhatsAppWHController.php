<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\MessagingProfile;
use Iquesters\SmartMessenger\Models\MessagingProfileMeta;

class WhatsAppWHController extends Controller
{
    /**
     * Handle WhatsApp webhook (GET + POST)
     */
    public function handle(Request $request)
    {
        try {
            /**
             * ---------------------------------------------
             * 1️⃣ WEBHOOK VERIFICATION (GET)
             * ---------------------------------------------
             */
            Log::debug('WhatsApp Webhook Received', [
                'method' => $request->method(),
                'all_params' => $request->all(),
                'query_params' => $request->query(),
            ]);

            // ⚠️ IMPORTANT: Laravel converts dots to underscores in parameter names
            // So hub.mode becomes hub_mode, hub.verify_token becomes hub_verify_token
            if (
                $request->isMethod('get') &&
                $request->input('hub_mode') === 'subscribe'
            ) {
                $verifyToken = $request->input('hub_verify_token');
                $challenge = $request->input('hub_challenge');

                Log::info('WhatsApp Webhook Verification Attempt', [
                    'hub_mode' => $request->input('hub_mode'),
                    'hub_verify_token' => $verifyToken,
                    'hub_challenge' => $challenge,
                ]);

                $meta = MessagingProfileMeta::where('meta_key', 'webhook_verify_token')
                    ->where('meta_value', $verifyToken)
                    ->first();

                if (!$meta) {
                    Log::warning('WhatsApp webhook verification failed - token not found', [
                        'received_token' => $verifyToken,
                        'all_tokens' => MessagingProfileMeta::where('meta_key', 'webhook_verify_token')
                            ->pluck('meta_value')
                            ->toArray()
                    ]);

                    return response('Invalid verification token', 403);
                }

                Log::info('WhatsApp webhook verification SUCCESS');

                // ⚠️ MUST be plain text, no JSON
                return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
            }


            /**
             * ---------------------------------------------
             * 2️⃣ INCOMING MESSAGE (POST)
             * ---------------------------------------------
             */
            $payload = $request->all();

            Log::info('WhatsApp Webhook Incoming', [
                'event' => 'message_received'
            ]);

            $phoneNumberId = data_get(
                $payload,
                'entry.0.changes.0.value.metadata.phone_number_id'
            );

            if (!$phoneNumberId) {
                Log::warning('WhatsApp payload missing phone_number_id');
                return response()->json(['status' => 'ignored'], 200);
            }

            /**
             * ---------------------------------------------
             * 3️⃣ RESOLVE MESSAGING PROFILE
             * ---------------------------------------------
             */
            $profile = MessagingProfile::where('status', 'active')
                ->whereHas('metas', function ($q) use ($phoneNumberId) {
                    $q->where('meta_key', 'whatsapp_phone_number_id')
                      ->where('meta_value', $phoneNumberId);
                })
                ->with('metas')
                ->first();

            if (!$profile) {
                Log::warning('No MessagingProfile found', [
                    'phone_number_id' => $phoneNumberId
                ]);
                return response()->json(['status' => 'ignored'], 200);
            }

            /**
             * ---------------------------------------------
             * 4️⃣ PROCESS MESSAGES
             * ---------------------------------------------
             */
            $messages = data_get($payload, 'entry.0.changes.0.value.messages', []);
            $metadata = data_get($payload, 'entry.0.changes.0.value.metadata');

            foreach ($messages as $message) {
                $this->processMessage($profile, $message, $payload, $metadata);
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Throwable $e) {
            Log::error('WhatsApp Webhook Fatal Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed'
            ], 500);
        }
    }

    /**
     * ---------------------------------------------
     * Process Single Message
     * ---------------------------------------------
     */
    private function processMessage(
        MessagingProfile $profile,
        array $message,
        array $rawPayload,
        ?array $metadata
    ): void {
        // Prevent duplicates
        if (Message::where('message_id', $message['id'])->exists()) {
            return;
        }

        Message::create([
            'messaging_profile_id' => $profile->id,
            'message_id'           => $message['id'],
            'from'                 => $message['from'],
            'to'                   => $metadata['display_phone_number']
                                        ?? $metadata['phone_number_id']
                                        ?? null,
            'message_type'         => $message['type'],
            'content'              => $this->extractMessageContent($message),
            'timestamp'            => now()->setTimestamp($message['timestamp']),
            'status'               => 'received',
            'raw_payload'          => $rawPayload,
        ]);

        // Only reply to text messages
        if ($message['type'] === 'text') {
            $this->sendReply($profile, $message);
            $this->markMessageAsRead($profile, $message);
        }
    }

    /**
     * ---------------------------------------------
     * Send WhatsApp Reply
     * ---------------------------------------------
     */
    private function sendReply(MessagingProfile $profile, array $message): void
    {
        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            Log::error('Missing WhatsApp credentials', [
                'profile_id' => $profile->id
            ]);
            return;
        }

        Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $message['from'],
                'text' => [
                    'body' => 'Echo: ' . ($message['text']['body'] ?? '')
                ],
                'context' => [
                    'message_id' => $message['id']
                ]
            ]
        );
    }

    /**
     * ---------------------------------------------
     * Mark Message as Read
     * ---------------------------------------------
     */
    private function markMessageAsRead(MessagingProfile $profile, array $message): void
    {
        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            return;
        }

        Http::withToken($token)->post(
            "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $message['id']
            ]
        );
    }

    /**
     * ---------------------------------------------
     * Extract Message Content
     * ---------------------------------------------
     */
    private function extractMessageContent(array $message): string
    {
        return match ($message['type']) {
            'text'     => $message['text']['body'] ?? '',
            'image'    => $message['image']['caption'] ?? 'Image received',
            'video'    => $message['video']['caption'] ?? 'Video received',
            'audio'    => 'Audio received',
            'document' => $message['document']['filename'] ?? 'Document received',
            'location' => 'Location: ' .
                ($message['location']['latitude'] ?? '') . ',' .
                ($message['location']['longitude'] ?? ''),
            default    => 'Unsupported message type',
        };
    }
}