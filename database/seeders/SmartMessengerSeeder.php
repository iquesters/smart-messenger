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
                'icon'  => 'fas fa-tower-broadcast',
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
     * Entity definitions with fields and metadata
     */
    protected array $entities = [
        'messages' => [
            'fields' => [],
            'meta_fields' => [
                'media_size' => [
                    'meta_key' => 'media_size',
                    'type' => 'string',
                    'label' => 'Media Size',
                    'required' => false,
                    'nullable' => true,
                ],
                'media_driver' => [
                    'meta_key' => 'media_driver',
                    'type' => 'string',
                    'label' => 'Media Driver',
                    'required' => false,
                    'nullable' => true,
                ],
                'media_path' => [
                    'meta_key' => 'media_path',
                    'type' => 'string',
                    'label' => 'Media Path',
                    'required' => false,
                    'nullable' => true,
                ],
                'media_url' => [
                    'meta_key' => 'media_url',
                    'type' => 'string',
                    'label' => 'Media URL',
                    'required' => false,
                    'nullable' => true,
                ],
                'mime_type' => [
                    'meta_key' => 'mime_type',
                    'type' => 'string',
                    'label' => 'Mime Type',
                    'required' => false,
                    'nullable' => true,
                ],
                'chatbot_handover_summary' => [
                    'meta_key' => 'chatbot_handover_summary',
                    'type' => 'text',
                    'label' => 'Chatbot Handover Summary',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
                'chatbot_handover_action_id' => [
                    'meta_key' => 'chatbot_handover_action_id',
                    'type' => 'string',
                    'label' => 'Chatbot Handover Action ID',
                    'required' => false,
                    'nullable' => true,
                ],
                'forwarded_from' => [
                    'meta_key' => 'forwarded_from',
                    'type' => 'string',
                    'label' => 'Forwarded From',
                    'required' => false,
                    'nullable' => true,
                ],
            ],
            'metas' => [],
        ],
        'contacts' => [
            'fields' => [],
            'meta_fields' => [],
            'metas' => [],
        ],
        'channel_providers' => [
            'fields' => [],
            'meta_fields' => [
                'description' => [
                    'meta_key' => 'description',
                    'type' => 'text',
                    'label' => 'Description',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
                'icon' => [
                    'meta_key' => 'icon',
                    'type' => 'text',
                    'label' => 'Icon',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
            ],
            'metas' => [],
        ],
        'channels' => [
            'fields' => [],
            'meta_fields' => [
                'webhook_verify_token' => [
                    'meta_key' => 'webhook_verify_token',
                    'type' => 'string',
                    'label' => 'Webhook Verify Token',
                    'required' => false,
                    'nullable' => true,
                ],
                'country_code' => [
                    'meta_key' => 'country_code',
                    'type' => 'string',
                    'label' => 'Country Code',
                    'required' => false,
                    'nullable' => true,
                ],
                'whatsapp_number' => [
                    'meta_key' => 'whatsapp_number',
                    'type' => 'string',
                    'label' => 'WhatsApp Number',
                    'required' => false,
                    'nullable' => true,
                ],
                'whatsapp_phone_number_id' => [
                    'meta_key' => 'whatsapp_phone_number_id',
                    'type' => 'string',
                    'label' => 'WhatsApp Phone Number ID',
                    'required' => false,
                    'nullable' => true,
                ],
                'whatsapp_business_id' => [
                    'meta_key' => 'whatsapp_business_id',
                    'type' => 'string',
                    'label' => 'WhatsApp Business ID',
                    'required' => false,
                    'nullable' => true,
                ],
                'system_user_token' => [
                    'meta_key' => 'system_user_token',
                    'type' => 'text',
                    'label' => 'System User Token',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
                'workflow_ids' => [
                    'meta_key' => 'workflow_ids',
                    'type' => 'json',
                    'label' => 'Workflow IDs',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
                'support_team_ids' => [
                    'meta_key' => 'support_team_ids',
                    'type' => 'json',
                    'label' => 'Support Team IDs',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
                'support_user_ids' => [
                    'meta_key' => 'support_user_ids',
                    'type' => 'json',
                    'label' => 'Support User IDs',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
            ],
            'metas' => [],
        ],
        'workflows' => [
            'fields' => [],
            'meta_fields' => [
                'workflow_jobs' => [
                    'meta_key' => 'workflow_jobs',
                    'type' => 'json',
                    'label' => 'Workflow Jobs',
                    'required' => false,
                    'nullable' => true,
                    'input_type' => 'textarea',
                ],
            ],
            'metas' => [],
        ],
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
                'status'     => 'active',
                'meta' => [
                    'description' => 'WhatsApp messaging provider',
                    'icon'        => '<i class="fa-brands fa-whatsapp" style="color:#25D366"></i>',
                ],
            ],
            [
                'name'       => 'Telegram',
                'small_name' => 'telegram',
                'nature'     => 'messaging',
                'status'     => 'draft',
                'meta' => [
                    'description' => 'Telegram messaging provider',
                    'icon'        => '<i class="fa-brands fa-telegram" style="color:#229ED9"></i>',
                ],
            ],
            [
                'name'       => 'Facebook Messenger',
                'small_name' => 'facebook_messenger',
                'nature'     => 'messaging',
                'status'     => 'draft',
                'meta' => [
                    'description' => 'Facebook Messenger provider',
                    'icon'        => '<i class="fa-brands fa-facebook-messenger" style="color:#0084FF"></i>',
                ],
            ],
            [
                'name'       => 'Instagram',
                'small_name' => 'instagram',
                'nature'     => 'messaging',
                'status'     => 'draft',
                'meta' => [
                    'description' => 'Instagram messaging provider',
                    'icon'        => '<i class="fa-brands fa-instagram" style="color:#E4405F"></i>',
                ],
            ],
            [
                'name'       => 'Gmail',
                'small_name' => 'gmail',
                'nature'     => 'messaging',
                'status'     => 'active',
                'meta' => [
                    'description' => 'Gmail messaging provider',
                    'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="16px" height="16px"><path fill="#4caf50" d="M45,16.2l-5,2.75l-5,4.75L35,40h7c1.657,0,3-1.343,3-3V16.2z"/><path fill="#1e88e5" d="M3,16.2l3.614,1.71L13,23.7V40H6c-1.657,0-3-1.343-3-3V16.2z"/><polygon fill="#e53935" points="35,11.2 24,19.45 13,11.2 12,17 13,23.7 24,31.95 35,23.7 36,17"/><path fill="#c62828" d="M3,12.298V16.2l10,7.5V11.2L9.876,8.859C9.132,8.301,8.228,8,7.298,8h0C4.924,8,3,9.924,3,12.298z"/><path fill="#fbc02d" d="M45,12.298V16.2l-10,7.5V11.2l3.124-2.341C38.868,8.301,39.772,8,40.702,8h0 C43.076,8,45,9.924,45,12.298z"/></svg>',
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
                    'status'     => $provider['status'],
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
                        'status'     => $provider['status'],
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