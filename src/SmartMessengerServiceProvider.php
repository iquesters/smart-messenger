<?php

namespace Iquesters\SmartMessenger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Console\Command;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\Enums\Module;
use Iquesters\SmartMessenger\Config\SmartMessengerConf;
use Iquesters\SmartMessenger\Database\Seeders\SmartMessengerSeeder;
use Iquesters\SmartMessenger\Services\ContactService;

class SmartMessengerServiceProvider extends ServiceProvider
{
    public function register()
    {
        ConfProvider::register(Module::SMART_MESSENGER, SmartMessengerConf::class);
        
        // Register Services
        $this->registerServices();
        
        // Register Commands
        $this->registerSeedCommand();
    }
    
    public function boot()
    {
        // Load web routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        
        // Load API routes with proper configuration
        $this->registerApiRoutes();
        
        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smartmessenger');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                'command.smart-messenger.seed'
            ]);
        }
    }

    /**
     * Register package services
     */
    protected function registerServices(): void
    {
        // Register ContactService as singleton
        $this->app->singleton(ContactService::class, function ($app) {
            return new ContactService();
        });

        // Add more services here as needed
        // $this->app->singleton(MessageService::class, function ($app) {
        //     return new MessageService();
        // });
    }

    /**
     * Register API routes with proper middleware and prefix
     */
    protected function registerApiRoutes(): void
    {
        Route::group([
            'middleware' => ['web', 'auth'],
            'prefix' => 'api/smart-messenger',
            'namespace' => 'Iquesters\SmartMessenger\Http\Controllers\Api',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    /**
     * Register the seed command
     */
    protected function registerSeedCommand(): void
    {
        $this->app->singleton('command.smart-messenger.seed', function ($app) {
            return new class extends Command {
                protected $signature = 'smart-messenger:seed';
                protected $description = 'Seed Smart Messenger module data';

                public function handle()
                {
                    $this->info('Running Smart Messenger Seeder...');
                    $seeder = new SmartMessengerSeeder();
                    $seeder->setCommand($this);
                    $seeder->run();
                    $this->info('Smart Messenger seeding completed!');
                    return 0;
                }
            };
        });
    }
}