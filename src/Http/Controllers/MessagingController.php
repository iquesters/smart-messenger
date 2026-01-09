<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\Channel;

class MessagingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        /**
         * ---------------------------------------------------------
         * Load organisation IDs for current user (via trait)
         * ---------------------------------------------------------
         */
        $organisationIds = collect();

        if (method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        /**
         * ---------------------------------------------------------
         * Load messaging profiles
         * - User owned channels
         * - Organisation shared channels
         * ---------------------------------------------------------
         */
        $profiles = Channel::query()
            ->where('created_by', $user->id)

            // ✅ Organisation access via trait (pivot)
            ->orWhere(function ($query) use ($organisationIds) {
                if (
                    $organisationIds->isNotEmpty() &&
                    method_exists(Channel::class, 'organisations')
                ) {
                    $query->orWhereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with(['metas', 'provider'])
            ->get();

        Log::info('Fetched profiles (user + organisation)', [
            'user_id' => $user->id,
            'count' => $profiles->count()
        ]);

        /**
         * ---------------------------------------------------------
         * Extract WhatsApp numbers from profiles
         * ---------------------------------------------------------
         */
        $numbers = [];
        foreach ($profiles as $profile) {
            $phone   = $profile->getMeta('whatsapp_number');
            $country = $profile->getMeta('country_code');

            if ($phone) {
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number'     => ($country ?? '') . $phone,
                    'name'       => $profile->name,
                    'icon'       => $profile->provider?->getMeta('icon') ?? ''
                ];
            }
        }

        /**
         * ---------------------------------------------------------
         * Selected number / contact
         * ---------------------------------------------------------
         */
        $selectedNumber  = $request->get('number');
        $selectedContact = $request->get('contact');

        if (!$selectedNumber && count($numbers) > 0) {
            $selectedNumber = $numbers[0]['number'];
        }

        $messages = collect();
        $contacts = [];
        $allMessages = collect();
        $profile = null;
        $contactsLookup = [];
        $selectedContactName = null;

        /**
         * ---------------------------------------------------------
         * Load profile + provider identifier
         * ---------------------------------------------------------
         */
        if ($selectedNumber) {

            $profile = $profiles->first(function ($p) use ($selectedNumber) {
                return ($p->getMeta('country_code') ?? '') . $p->getMeta('whatsapp_number') === $selectedNumber;
            });

            if ($profile) {

                /**
                 * Provider identifier (whatsapp_phone_number_id)
                 */
                $providerIdentifier = $profile->metas
                    ->where('meta_key', 'whatsapp_phone_number_id')
                    ->pluck('meta_value')
                    ->first();

                /**
                 * -------------------------------------------------
                 * Load contacts ONLY for this profile
                 * -------------------------------------------------
                 */
                if ($providerIdentifier) {
                    $contactsLookup = Contact::with('metas')
                        ->whereHas('metas', function ($query) use ($providerIdentifier) {
                            $query->where('meta_key', 'profile_details')
                                ->where(
                                    'meta_value',
                                    'LIKE',
                                    '%"provider_identifier":"' . $providerIdentifier . '"%'
                                );
                        })
                        ->get()
                        ->pluck('name', 'identifier')
                        ->toArray();
                }

                /**
                 * -------------------------------------------------
                 * All messages (table view)
                 * -------------------------------------------------
                 */
                $allMessages = Message::where('channel_id', $profile->id)
                    ->orderBy('timestamp', 'desc')
                    ->get();

                /**
                 * -------------------------------------------------
                 * Build contacts list from messages
                 * -------------------------------------------------
                 */
                $contacts = Message::where('channel_id', $profile->id)
                    ->select('from', 'to', 'content', 'timestamp')
                    ->orderBy('timestamp', 'desc')
                    ->get()
                    ->groupBy(function ($msg) use ($selectedNumber) {
                        return $msg->from === $selectedNumber ? $msg->to : $msg->from;
                    })
                    ->map(function ($msgs, $contactNumber) use ($profile, $contactsLookup) {

                        $lastMsg = $msgs->first();

                        return [
                            'number'         => $contactNumber,
                            'name'           => $contactsLookup[$contactNumber] ?? $contactNumber,
                            'provider_name'  => $profile->provider?->value ?? 'Unknown',
                            'provider_icon'  => $profile->provider?->getMeta('icon') ?? '',
                            'last_message'   => $lastMsg->content,
                            'last_timestamp' => $lastMsg->timestamp,
                        ];
                    })
                    ->sortByDesc('last_timestamp')
                    ->values()
                    ->toArray();

                /**
                 * -------------------------------------------------
                 * Selected contact messages
                 * -------------------------------------------------
                 */
                if ($selectedContact) {

                    $selectedContactName = $contactsLookup[$selectedContact] ?? $selectedContact;

                    $messages = Message::where('channel_id', $profile->id)
                        ->where(function ($query) use ($selectedContact) {
                            $query->where('from', $selectedContact)
                                ->orWhere('to', $selectedContact);
                        })
                        ->orderBy('timestamp', 'asc')
                        ->get();
                }
            }
        }

        /**
         * ---------------------------------------------------------
         * Render view
         * ---------------------------------------------------------
         */
        return view('smartmessenger::messages.index', [
            'numbers'             => $numbers,
            'selectedNumber'      => $selectedNumber,
            'contacts'            => $contacts,
            'selectedContact'     => $selectedContact,
            'selectedContactName' => $selectedContactName,
            'messages'            => $messages,
            'allMessages'         => $allMessages,
            'profile'             => $profile,
        ]);
    }

    /**
     * ---------------------------------------------------------
     * Send message
     * ---------------------------------------------------------
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'profile_id' => 'required|exists:channels,id',
            'to' => 'required|string',
            'message' => 'required|string'
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

            /**
             * 2️⃣ Save message locally
             */
            $message = Message::create([
                'channel_id' => $profile->id,
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