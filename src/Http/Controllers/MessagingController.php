<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\MessagingProfile;

class MessagingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Get all messaging profiles for this user
        $profiles = MessagingProfile::where('created_by', $user->id)
            ->with(['metas', 'provider'])
            ->get();
        Log::info('Fetched profiles', ['count' => $profiles->count()]);

        // Extract phone numbers from meta
        $numbers = [];
        foreach ($profiles as $profile) {
            $phone = $profile->getMeta('whatsapp_number');
            $country = $profile->getMeta('country_code');

            if ($phone) {
                $fullNumber = ($country ?? '') . $phone;
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number'     => $fullNumber,
                    'name'       => $profile->name,
                    'icon'       => $profile->provider?->getMetaValue('icon') ?? ''
                ];
            }
        }

        // Selected number and contact
        $selectedNumber = $request->get('number');
        $selectedContact = $request->get('contact');

        // Auto-select first number if none selected
        if (!$selectedNumber && count($numbers) > 0) {
            $selectedNumber = $numbers[0]['number'];
        }

        $messages = collect();
        $contacts = [];
        $allMessages = collect();
        $profile = null;

        if ($selectedNumber) {
            // Find related profile
            $profile = $profiles->first(function ($p) use ($selectedNumber) {
                return ($p->getMeta('country_code') ?? '') . $p->getMeta('whatsapp_number') === $selectedNumber;
            });

            if ($profile) {
                // Get all messages for table view
                $allMessages = Message::where('messaging_profile_id', $profile->id)
                    ->orderBy('timestamp', 'desc')
                    ->get();

                // Unique contacts from messages
                $contactsData = Message::where('messaging_profile_id', $profile->id)
                    ->select('from', 'to', 'content', 'timestamp')
                    ->orderBy('timestamp', 'desc')
                    ->get()
                    ->groupBy(function($msg) use ($selectedNumber) {
                        return $msg->from == $selectedNumber ? $msg->to : $msg->from;
                    })
                    ->map(function ($messages, $contactNumber) use ($profile) {
                        $lastMsg = $messages->first();

                        return [
                            'number'         => $contactNumber,
                            'provider_name'  => $profile->provider?->value ?? 'Unknown',
                            'provider_icon'  => $profile->provider?->getMetaValue('icon') ?? '',
                            'last_message'   => $lastMsg->content,
                            'last_timestamp' => $lastMsg->timestamp,
                        ];
                    })
                    ->sortByDesc('last_timestamp')
                    ->values()
                    ->toArray();

                $contacts = $contactsData;

                // Filter messages for selected contact
                if ($selectedContact) {
                    $messages = Message::where('messaging_profile_id', $profile->id)
                        ->where(function($query) use ($selectedContact) {
                            $query->where('from', $selectedContact)
                                ->orWhere('to', $selectedContact);
                        })
                        ->orderBy('timestamp', 'asc')
                        ->get();
                }
            }
        }

        return view('smartmessenger::messages.index', [
            'numbers'         => $numbers,
            'selectedNumber'  => $selectedNumber,
            'contacts'        => $contacts,
            'selectedContact' => $selectedContact,
            'messages'        => $messages,
            'allMessages'     => $allMessages,
            'profile'         => $profile,
        ]);
    }
    
    public function sendMessage(Request $request)
    {
        $request->validate([
            'profile_id' => 'required|exists:messaging_profiles,id',
            'to' => 'required|string',
            'message' => 'required|string'
        ]);

        $profile = MessagingProfile::with('metas')->findOrFail($request->profile_id);

        $token = $profile->getMeta('system_user_token');
        $phoneNumberId = $profile->getMeta('whatsapp_phone_number_id');

        if (!$token || !$phoneNumberId) {
            return response()->json(['error' => 'WhatsApp credentials missing'], 422);
        }

        try {
            /**
             * 1️⃣ Send to WhatsApp
             */
            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $request->to,
                    'type' => 'text',
                    'text' => [
                        'body' => $request->message
                    ]
                ]
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
                'messaging_profile_id' => $profile->id,
                'message_id' => $waMessageId,
                'from' => ($profile->getMeta('country_code') ?? '') . $profile->getMeta('whatsapp_number'),
                'to' => $request->to,
                'message_type' => 'text',
                'content' => $request->message,
                'timestamp' => now(),
                'status' => 'sent',
                'raw_payload' => $response->json()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $message
            ]);

        } catch (\Throwable $e) {
            Log::error('Send message exception', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message'
            ], 500);
        }
    }
}