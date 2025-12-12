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

        foreach ($profiles as $profile) {
            $phone = $profile->getMeta('whatsapp_number');
            $country = $profile->getMeta('country_code');

            if ($phone) {
                $numbers[] = [
                    'profile_id' => $profile->id,
                    'number'     => ($country ?? '') . $phone
                ];
            }
        }

        // 3️⃣ Filter messages when number selected
        $selectedNumber = $request->get('number');
        $messages = collect();

        if ($selectedNumber) {
            // Find related profile
            $profile = $profiles->first(function ($p) use ($selectedNumber) {
                return ($p->getMeta('country_code') ?? '') . $p->getMeta('whatsapp_number') === $selectedNumber;
            });

            if ($profile) {
                $messages = Message::where('messaging_profile_id', $profile->id)
                    ->orderBy('timestamp', 'asc')
                    ->get();
            }
        }

        return view('smartmessenger::messages.index', [
            'numbers'         => $numbers,
            'selectedNumber'  => $selectedNumber,
            'messages'        => $messages,
        ]);
    }
}