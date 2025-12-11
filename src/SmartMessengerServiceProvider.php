<?php

namespace Iquesters\SmartMessenger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Command;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\Enums\Module;
use Iquesters\SmartMessenger\Config\SmartMessengerConf;
use Iquesters\SmartMessenger\Database\Seeders\SmartMessengerSeeder;

class SmartMessengerServiceProvider extends ServiceProvider
{
    public function register()
    {
        ConfProvider::register(Module::SMART_MESSENGER, SmartMessengerConf::class);

        $this->registerSeedCommand();
    }
    
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smartmessenger');
        $this->loadMigrationsFrom(__DIR__. '/../database/migrations');
        
        if ($this->app->runningInConsole()) {
            $this->commands([
                'command.smart-messenger.seed'
            ]);
        }
    }

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