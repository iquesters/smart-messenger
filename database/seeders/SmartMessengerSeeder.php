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
                "icon" => "fas fa-building-columns",
                "label" => "Messages",
                "route" => "messages.index",
            ],
            [
                "icon" => "fas fa-building-columns",
                "label" => "Send Messages",
                "route" => "messages.send",
            ],
            [
                "icon" => "fas fa-building-columns",
                "label" => "Profiles",
                "route" => "profiles.index",
            ]
            
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