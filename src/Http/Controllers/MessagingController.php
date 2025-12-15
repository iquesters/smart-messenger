<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Models\MessagingProfile;

class MessagingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // 1️⃣ Get all messaging profiles for this user
        $profiles = MessagingProfile::where('created_by', $user->id)
            ->with('metas')
            ->get();

        // 2️⃣ Extract phone numbers from meta
        $numbers = [];
        $myNumbers = [];

        foreach ($profiles as $profile) {
            $phone = $profile->getMeta('whatsapp_number');
            $country = $profile->getMeta('country_code');

            if ($phone) {
                $fullNumber = ($country ?? '') . $phone;
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number'     => $fullNumber
                ];
                $myNumbers[] = $fullNumber;
            }
        }

        $selectedNumber = $request->get('number');
        $selectedContact = $request->get('contact');
        $messages = collect();
        $contacts = [];
        $allMessages = collect();

        if ($selectedNumber) {
            // Find related profile
            $profile = $profiles->first(function ($p) use ($selectedNumber) {
                return ($p->getMeta('country_code') ?? '') . $p->getMeta('whatsapp_number') === $selectedNumber;
            });

            if ($profile) {
                // Get ALL messages for this profile (for table view)
                $allMessages = Message::where('messaging_profile_id', $profile->id)
                    ->orderBy('timestamp', 'desc')
                    ->get();

                // 3️⃣ Get unique contacts from messages
                $contactsData = Message::where('messaging_profile_id', $profile->id)
                    ->select('from', 'to', 'content', 'timestamp')
                    ->orderBy('timestamp', 'desc')
                    ->get()
                    ->groupBy(function($msg) use ($selectedNumber) {
                        // Group by the contact number (not my number)
                        return $msg->from == $selectedNumber ? $msg->to : $msg->from;
                    })
                    ->map(function($messages, $contactNumber) {
                        $lastMsg = $messages->first();
                        return [
                            'number' => $contactNumber,
                            'last_message' => $lastMsg->content,
                            'last_timestamp' => $lastMsg->timestamp,
                        ];
                    })
                    ->sortByDesc('last_timestamp')
                    ->values()
                    ->toArray();

                $contacts = $contactsData;

                // 4️⃣ If a contact is selected, filter messages for that contact
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
            'allMessages'     => $allMessages, // For table view
        ]);
    }
}