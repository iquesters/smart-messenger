<?php

namespace Iquesters\SmartMessenger\Database\Seeders;

use Iquesters\Foundation\Database\Seeders\BaseSeeder;

class SmartMessengerSeeder extends BaseSeeder
{
    protected string $moduleName = 'smart-messenger';
    protected string $description = 'Smart Messenger module';
    protected array $metas = [
        'module_icon' => 'fas fa-building',
        'module_sidebar_menu' => [
            [
                "icon"  => "fas fa-inbox",
                "label" => "Inbox",
                "route" => "messages.index",
            ],
            [
                "icon"  => "fas fa-address-book",
                "label" => "Contacts",
                "route" => "contacts.index",
            ],
            [
                "icon"  => "fas fa-id-badge",
                "label" => "Profiles",
                "route" => "profiles.index",
            ],
            [
                "icon"  => "fas fa-plug",
                "label" => "Integrations",
                "route" => "integrations.index",
            ],
        ]
    ];

    protected array $permissions = [
        'view-organisation-profiles',
        'create-organisation-profiles',
        'edit-organisation-profiles',
        'delete-organisation-profiles'
    ];
    
    /**
     * Implement abstract method from BaseSeeder
     */
    protected function seedCustom(): void
    {
        // Add custom seeding logic here if needed
        // Leave empty if none
    }
}