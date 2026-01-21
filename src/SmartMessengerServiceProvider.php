<?php

namespace Iquesters\SmartMessenger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\Enums\Module;
use Iquesters\SmartMessenger\Config\SmartMessengerConf;
use Iquesters\SmartMessenger\Database\Seeders\SmartMessengerSeeder;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\Foundation\Services\QueueManager;
use Iquesters\SmartMessenger\Console\Commands\MonitorQueuesCommand;

class SmartMessengerServiceProvider extends ServiceProvider
{
    public function register()
    {
        ConfProvider::register(Module::SMART_MESSENGER, SmartMessengerConf::class);
        
        // Register Services
        $this->registerServices();
        
        // Register Commands
        $this->registerCommands();
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
                'command.smart-messenger.seed',
                'command.smart-messenger.monitor-queues',
            ]);
        }

        // Auto-start scheduler when running serve command
        $this->autoStartScheduler();

        // Register scheduled tasks
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            // Monitor queues every minute
            $schedule->command('smart-messenger:monitor-queues')
                ->everyThirtySeconds()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * Auto-start scheduler when php artisan serve is running
     */
    protected function autoStartScheduler(): void
    {
        // Only run in web/serve context, not in console commands
        if (!$this->app->runningInConsole() || $this->isServeCommand()) {
            // Start scheduler in background on first request
            $this->app->booted(function () {
                $lockFile = storage_path('framework/schedule-worker.lock');
                
                // Check if scheduler is already running
                if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 120) {
                    // Update lock file
                    @file_put_contents($lockFile, time());
                    
                    // Start scheduler in background
                    $this->startSchedulerWorker();
                }
            });
        }
    }

    /**
     * Check if current command is serve
     */
    protected function isServeCommand(): bool
    {
        if (!isset($_SERVER['argv'])) {
            return false;
        }
        
        $argv = $_SERVER['argv'];
        return in_array('serve', $argv);
    }

    /**
     * Start scheduler worker in background
     */
    protected function startSchedulerWorker(): void
    {
        $cmd = sprintf('php %s/artisan schedule:work', base_path());
        
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B " . $cmd . " > NUL 2>&1", "r"));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
        
        \Illuminate\Support\Facades\Log::info('Schedule worker auto-started with serve command');
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

        // Register QueueManager as singleton
        $this->app->singleton(QueueManager::class, function ($app) {
            return new QueueManager();
        });
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
     * Register the commands
     */
    protected function registerCommands(): void
    {
        // Register seed command
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

        // Register monitor queues command
        $this->app->singleton('command.smart-messenger.monitor-queues', function ($app) {
            return new MonitorQueuesCommand();
        });
    }
}