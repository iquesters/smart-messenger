<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Models\Contact;
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

            Log::info('WhatsApp Webhook POST Received', [
                'object' => $payload['object'] ?? null,
                'full_payload' => $payload
            ]);

            // Handle status updates (delivered, read, sent, failed)
            $this->handleStatusUpdates($payload);

            // Extract phone_number_id from metadata
            $phoneNumberId = data_get(
                $payload,
                'entry.0.changes.0.value.metadata.phone_number_id'
            );

            if (!$phoneNumberId) {
                Log::warning('WhatsApp payload missing phone_number_id', [
                    'payload' => $payload
                ]);
                return response()->json(['status' => 'ignored', 'reason' => 'no_phone_number_id'], 200);
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
                    'phone_number_id' => $phoneNumberId,
                    'available_profiles' => MessagingProfile::where('status', 'active')
                        ->with('metas')
                        ->get()
                        ->map(function($p) {
                            return [
                                'id' => $p->id,
                                'name' => $p->name,
                                'phone_number_id' => $p->getMeta('whatsapp_phone_number_id')
                            ];
                        })
                ]);
                return response()->json(['status' => 'ignored', 'reason' => 'no_profile_found'], 200);
            }

            Log::info('MessagingProfile found', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name
            ]);

            /**
             * ---------------------------------------------
             * 4️⃣ PROCESS MESSAGES
             * ---------------------------------------------
             */
            $messages = data_get($payload, 'entry.0.changes.0.value.messages', []);
            $metadata = data_get($payload, 'entry.0.changes.0.value.metadata');
            $contacts = data_get($payload, 'entry.0.changes.0.value.contacts', []);

            if (empty($messages)) {
                Log::info('No messages in payload (might be status update only)');
                return response()->json(['status' => 'success', 'messages_processed' => 0], 200);
            }

            $processedCount = 0;
            foreach ($messages as $message) {
                $this->processMessage($profile, $message, $payload, $metadata, $contacts);
                $processedCount++;
            }

            Log::info('Messages processed successfully', [
                'count' => $processedCount
            ]);

            return response()->json([
                'status' => 'success',
                'messages_processed' => $processedCount
            ], 200);

        } catch (\Throwable $e) {
            Log::error('WhatsApp Webhook Fatal Error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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
     * Handle Status Updates (delivered, read, sent)
     * ---------------------------------------------
     */
    private function handleStatusUpdates(array $payload): void
    {
        $statuses = data_get($payload, 'entry.0.changes.0.value.statuses', []);

        foreach ($statuses as $status) {
            $messageId = $status['id'] ?? null;
            $newStatus = $status['status'] ?? null;

            if (!$messageId || !$newStatus) {
                continue;
            }

            $message = Message::where('message_id', $messageId)->first();

            if ($message) {
                $message->update([
                    'status' => $newStatus,
                    'raw_response' => $status
                ]);

                Log::info('Message status updated', [
                    'message_id' => $messageId,
                    'status' => $newStatus
                ]);
            }
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
        ?array $metadata,
        array $contacts = []
    ): void {
        $messageId = $message['id'] ?? null;

        if (!$messageId) {
            Log::warning('Message without ID', ['message' => $message]);
            return;
        }

        // Prevent duplicates
        if (Message::where('message_id', $messageId)->exists()) {
            Log::info('Duplicate message ignored', ['message_id' => $messageId]);
            return;
        }

        // Extract contact info
        $contactName = null;
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? null) === ($message['from'] ?? null)) {
                $contactName = $contact['profile']['name'] ?? null;
                break;
            }
        }

        // Prepare message data
        $messageData = [
            'messaging_profile_id' => $profile->id,
            'message_id'           => $messageId,
            'from'                 => $message['from'] ?? null,
            'to'                   => $metadata['display_phone_number'] 
                                        ?? $metadata['phone_number_id'] 
                                        ?? null,
            'message_type'         => $message['type'] ?? 'unknown',
            'content'              => $this->extractMessageContent($message),
            'timestamp'            => isset($message['timestamp']) 
                                        ? now()->setTimestamp($message['timestamp']) 
                                        : now(),
            'status'               => 'received',
            'raw_payload'          => $rawPayload,
        ];

        // Add contact name to raw_payload if available
        if ($contactName) {
            $messageData['raw_payload']['contact_name'] = $contactName;
        }

        $savedMessage = Message::create($messageData);

        Log::info('Message saved successfully', [
            'id' => $savedMessage->id,
            'message_id' => $messageId,
            'type' => $message['type'],
            'from' => $message['from']
        ]);
        
        /**
         * ---------------------------------------------
         * CONTACT CREATE / UPDATE
         * ---------------------------------------------
         */
        $identifier = $message['from'] ?? null;

        if ($identifier) {

            // Try to extract contact name from webhook
            $contactName = null;
            foreach ($contacts as $contact) {
                if (($contact['wa_id'] ?? null) === $identifier) {
                    $contactName = $contact['profile']['name'] ?? null;
                    break;
                }
            }

            // Create or fetch contact
            $contact = Contact::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'uid'    => (string) Str::ulid(),
                    'name'   => $contactName ?? $identifier,
                    'status' => 'active',
                ]
            );

            // Build profile_details meta
            $profileDetails = [
                'uid'                 => $contact->uid,
                'identifier'          => $identifier,
                'provider'            => $profile->id, // provider_id
                'provider_identifier' => $profile->getMeta('whatsapp_phone_number_id'),
                'default'             => true,
                'preferred'           => true,
                'status'              => 'active',
            ];

            // Save meta as JSON
            $contact->setMetaValue(
                'profile_details',
                json_encode($profileDetails)
            );

            Log::info('Contact resolved from WhatsApp message', [
                'contact_id' => $contact->id,
                'identifier' => $identifier,
                'profile_id' => $profile->id,
            ]);
        }

        // Optional: Mark message as read
        // Uncomment the line below if you want messages to be marked as read automatically
        // $this->markMessageAsRead($profile, $message);
        
        // Optional: Send auto-reply
        // Uncomment the lines below if you want to send automatic replies
        // if ($message['type'] === 'text') {
        //     $this->sendReply($profile, $message);
        // }
    }

    /**
     * ---------------------------------------------
     * Send WhatsApp Reply
     * ---------------------------------------------
     */
    private function sendReply(MessagingProfile $profile, array $message): void
    {
        try {
            $token = $profile->getMeta('system_user_token');
            $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

            if (!$token || !$phoneNumberId) {
                Log::error('Missing WhatsApp credentials for reply', [
                    'profile_id' => $profile->id,
                    'has_token' => !empty($token),
                    'has_phone_id' => !empty($phoneNumberId)
                ]);
                return;
            }

            $response = Http::withToken($token)->post(
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

            if ($response->successful()) {
                Log::info('Reply sent successfully', [
                    'to' => $message['from'],
                    'message_id' => $message['id']
                ]);
            } else {
                Log::error('Failed to send reply', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception sending reply', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ---------------------------------------------
     * Mark Message as Read
     * ---------------------------------------------
     */
    private function markMessageAsRead(MessagingProfile $profile, array $message): void
    {
        try {
            $token = $profile->getMeta('system_user_token');
            $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

            if (!$token || !$phoneNumberId) {
                return;
            }

            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $message['id']
                ]
            );

            if ($response->successful()) {
                Log::info('Message marked as read', [
                    'message_id' => $message['id']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception marking message as read', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ---------------------------------------------
     * Extract Message Content Based on Type
     * ---------------------------------------------
     */
    private function extractMessageContent(array $message): string
    {
        $type = $message['type'] ?? 'unknown';

        return match ($type) {
            'text' => $message['text']['body'] ?? '',
            
            'image' => json_encode([
                'caption' => $message['image']['caption'] ?? '',
                'mime_type' => $message['image']['mime_type'] ?? '',
                'sha256' => $message['image']['sha256'] ?? '',
                'id' => $message['image']['id'] ?? ''
            ]),
            
            'video' => json_encode([
                'caption' => $message['video']['caption'] ?? '',
                'mime_type' => $message['video']['mime_type'] ?? '',
                'sha256' => $message['video']['sha256'] ?? '',
                'id' => $message['video']['id'] ?? ''
            ]),
            
            'audio' => json_encode([
                'mime_type' => $message['audio']['mime_type'] ?? '',
                'sha256' => $message['audio']['sha256'] ?? '',
                'id' => $message['audio']['id'] ?? '',
                'voice' => $message['audio']['voice'] ?? false
            ]),
            
            'document' => json_encode([
                'filename' => $message['document']['filename'] ?? '',
                'caption' => $message['document']['caption'] ?? '',
                'mime_type' => $message['document']['mime_type'] ?? '',
                'sha256' => $message['document']['sha256'] ?? '',
                'id' => $message['document']['id'] ?? ''
            ]),
            
            'sticker' => json_encode([
                'mime_type' => $message['sticker']['mime_type'] ?? '',
                'sha256' => $message['sticker']['sha256'] ?? '',
                'id' => $message['sticker']['id'] ?? '',
                'animated' => $message['sticker']['animated'] ?? false
            ]),
            
            'location' => json_encode([
                'latitude' => $message['location']['latitude'] ?? '',
                'longitude' => $message['location']['longitude'] ?? '',
                'name' => $message['location']['name'] ?? '',
                'address' => $message['location']['address'] ?? ''
            ]),
            
            'contacts' => json_encode($message['contacts'] ?? []),
            
            'button' => json_encode([
                'text' => $message['button']['text'] ?? '',
                'payload' => $message['button']['payload'] ?? ''
            ]),
            
            'interactive' => json_encode([
                'type' => $message['interactive']['type'] ?? '',
                'button_reply' => $message['interactive']['button_reply'] ?? null,
                'list_reply' => $message['interactive']['list_reply'] ?? null
            ]),
            
            'reaction' => json_encode([
                'message_id' => $message['reaction']['message_id'] ?? '',
                'emoji' => $message['reaction']['emoji'] ?? ''
            ]),
            
            default => json_encode([
                'type' => $type,
                'raw' => $message
            ])
        };
    }
}