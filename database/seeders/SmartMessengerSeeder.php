<?php

namespace Iquesters\SmartMessenger\Database\Seeders;

use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SmartMessengerSeeder extends BaseSeeder
{
    protected string $moduleName = 'smart-messenger';
    protected string $description = 'Smart Messenger module';

    protected array $metas = [
        'module_icon' => 'fas fa-message',
        'module_sidebar_menu' => [
            [
                'icon'  => 'fas fa-inbox',
                'label' => 'Inbox',
                'route' => 'messages.index',
            ],
            [
                'icon'  => 'fas fa-address-book',
                'label' => 'Contacts',
                'route' => 'contacts.index',
            ],
            [
                'icon'  => 'fas fa-id-badge',
                'label' => 'Channels',
                'route' => 'channels.index',
            ],
        ],
    ];

    protected array $permissions = [
        'view-organisation-channels',
        'create-organisation-channels',
        'edit-organisation-channels',
        'delete-organisation-channels',
    ];

    /**
     * Custom Smart Messenger seeding
     */
    protected function seedCustom(): void
    {
        $now = Carbon::now();

        $providers = [
            [
                'name'       => 'WhatsApp',
                'small_name' => 'whatsapp',
                'nature'     => 'messaging',
                'meta' => [
                    'description' => 'WhatsApp messaging provider',
                    'icon'        => '<i class="fa-brands fa-whatsapp" style="color:#25D366"></i>',
                ],
            ],
            [
                'name'       => 'Telegram',
                'small_name' => 'telegram',
                'nature'     => 'messaging',
                'meta' => [
                    'description' => 'Telegram messaging provider',
                    'icon'        => '<i class="fa-brands fa-telegram" style="color:#229ED9"></i>',
                ],
            ],
            [
                'name'       => 'Facebook Messenger',
                'small_name' => 'facebook_messenger',
                'nature'     => 'messaging',
                'meta' => [
                    'description' => 'Facebook Messenger provider',
                    'icon'        => '<i class="fa-brands fa-facebook-messenger" style="color:#0084FF"></i>',
                ],
            ],
            [
                'name'       => 'Instagram',
                'small_name' => 'instagram',
                'nature'     => 'messaging',
                'meta' => [
                    'description' => 'Instagram messaging provider',
                    'icon'        => '<i class="fa-brands fa-instagram" style="color:#E4405F"></i>',
                ],
            ],
        ];

        foreach ($providers as $provider) {

            /**
             * 1️⃣ Fetch existing provider (by unique key)
             */
            $existingProvider = DB::table('channel_providers')
                ->where('small_name', $provider['small_name'])
                ->first();

            /**
             * 2️⃣ Insert or update provider
             */
            if (!$existingProvider) {
                $providerId = DB::table('channel_providers')->insertGetId([
                    'uid'        => (string) Str::ulid(),
                    'name'       => $provider['name'],
                    'small_name' => $provider['small_name'],
                    'nature'     => $provider['nature'],
                    'status'     => 'active',
                    'created_by' => 0,
                    'updated_by' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $providerId = $existingProvider->id;

                DB::table('channel_providers')
                    ->where('id', $providerId)
                    ->update([
                        'name'       => $provider['name'],
                        'nature'     => $provider['nature'],
                        'status'     => 'active',
                        'updated_by' => 0,
                        'updated_at' => $now,
                    ]);
            }

            /**
             * 3️⃣ Sync provider meta
             */
            foreach ($provider['meta'] as $metaKey => $metaValue) {
                DB::table('channel_provider_metas')->updateOrInsert(
                    [
                        'ref_parent' => $providerId,
                        'meta_key'   => $metaKey,
                    ],
                    [
                        'meta_value' => $metaValue,
                        'status'     => 'active',
                        'created_by' => 0,
                        'updated_by' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
}