<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Iquesters\SmartMessenger\Models\Contact;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\Foundation\Models\MasterData;
use App\Models\User;

class ContactService
{
    /**
     * ======================================================
     * NEW: Get channels accessible by user OR organisation
     * ======================================================
     */
    private function getAccessibleChannels(int $userId)
    {
        $user = User::find($userId);

        $organisationIds = collect();

        if ($user && method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        return Channel::query()
            ->where('user_id', $userId)
            ->orWhere(function ($query) use ($organisationIds) {
                if ($organisationIds->isNotEmpty() && method_exists(Channel::class, 'organisations')) {
                    $query->whereHas('organisations', function ($q) use ($organisationIds) {
                        $q->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with(['metas', 'provider'])
            ->get();
    }

    /**
     * Create a new contact
     */
    public function createContact(array $data, int $userId): Contact
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'identifier' => 'required|string|max:50',
            'channel_id' => 'required|exists:channels,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // 🔥 UPDATED: Use accessible channels
        $channel = $this->getAccessibleChannels($userId)
            ->where('id', $data['channel_id'])
            ->first();

        if (!$channel) {
            throw new \Exception('Channel not found or access denied');
        }

        $organisationId = $channel->organisations->pluck('id')->first();

        $contact = Contact::where('identifier', $data['identifier'])
            ->whereHas('organisations', function ($q) use ($organisationId) {
                $q->where('organisations.id', $organisationId);
            })
            ->first();

        if (!$contact) {
            $contact = Contact::create([
                'uid' => (string) Str::ulid(),
                'name' => $data['name'],
                'identifier' => $data['identifier'],
                'status' => 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $contact->organisations()->attach($organisationId);
        }

        $providerIdentifier = $channel->metas
            ->where('meta_key', 'whatsapp_phone_number_id')
            ->pluck('meta_value')
            ->first();

        $contact->setMetaValue(
            $channel->uid,
            json_encode([
                'identifier' => $data['identifier'],
                'name' => $data['name'],
                'status' => 'active',
            ])
        );

        return $contact;
    }

    /**
     * Create or update contact from webhook
     * ❗ UNCHANGED
     */
public function createOrUpdateFromWebhook(
    string $identifier,
    ?string $name,
    Channel $channel
): Contact {

    $organisationId = $channel->organisations->pluck('id')->first();

    $cleanName = trim((string) $name);

    $contact = Contact::where('identifier', $identifier)
        ->whereHas('organisations', function ($q) use ($organisationId) {
            $q->where('organisations.id', $organisationId);
        })
        ->first();

    if (!$contact) {

        // CREATE CONTACT
        $contact = Contact::create([
            'uid' => (string) Str::ulid(),
            'name' => $cleanName !== '' ? $cleanName : $identifier,
            'identifier' => $identifier,
            'status' => 'active',
        ]);

        $contact->organisations()->attach($organisationId);

    } else {

        // 🔥 IMPORTANT PART: UPDATE NAME IF BETTER ONE ARRIVES
        if ($cleanName !== '' && $contact->name === $identifier) {
            $contact->update([
                'name' => $cleanName,
                'updated_by' => $channel->user_id,
            ]);
        }
    }

    // Update meta
    $contact->setMetaValue(
        $channel->uid,
        json_encode([
            'identifier' => $identifier,
            'name' => $cleanName !== '' ? $cleanName : $contact->name,
            'status' => 'active',
        ])
    );

    return $contact;
}

    /**
     * Update contact
     */
    public function updateContact(string $uid, array $data, int $userId): Contact
    {
        $channels = $this->getAccessibleChannels($userId);

        if ($channels->isEmpty()) {
            throw new \Exception('No accessible channels');
        }

        $providerIdentifiers = $channels->map(function ($channel) {
            return $channel->metas
                ->where('meta_key', 'whatsapp_phone_number_id')
                ->pluck('meta_value')
                ->first();
        })->filter()->unique();

        $organisationIds = $channels->flatMap(fn($c) => $c->organisations->pluck('id'))->unique();

        $contact = Contact::where('uid', $uid)
            ->whereHas('organisations', function ($q) use ($organisationIds) {
                $q->whereIn('organisations.id', $organisationIds);
            })
            ->first();

        if (!$contact) {
            throw new \Exception('Contact not found or access denied');
        }

        $contact->update([
            'name' => $data['name'],
            'updated_by' => $userId,
        ]);

        return $contact;
    }

    /**
     * Get contacts for user OR organisation channels
     */
    public function getUserContacts(int $userId)
    {
        $channels = $this->getAccessibleChannels($userId);

        if ($channels->isEmpty()) {
            return collect([]);
        }

        $channelNameByIdentifier = [];
        foreach ($channels as $channel) {
            $identifier = $channel->metas
                ->where('meta_key', 'whatsapp_phone_number_id')
                ->pluck('meta_value')
                ->first();

            if ($identifier) {
                $channelNameByIdentifier[$identifier] = $channel->name;
            }
        }

        $providerIdentifiers = collect(array_keys($channelNameByIdentifier));

        $organisationIds = $channels->flatMap(fn($c) => $c->organisations->pluck('id'))->unique();

        return Contact::with('metas')
            ->whereHas('organisations', function ($q) use ($organisationIds) {
                $q->whereIn('organisations.id', $organisationIds);
            })
            ->orderByDesc('created_at')
            ->get();
            }

    /**
     * Update profile meta
     * ❗ UNCHANGED
     */
    private function updateContactProfileDetails(
        Contact $contact,
        Channel $channel,
        ?string $providerIdentifier
    ): void {
        $contact->setMetaValue('profile_details', json_encode([
            'uid' => $contact->uid,
            'identifier' => $contact->identifier,
            'provider' => $channel->channel_provider_id,
            'provider_identifier' => $providerIdentifier,
            'status' => 'active',
        ]));
    }
}