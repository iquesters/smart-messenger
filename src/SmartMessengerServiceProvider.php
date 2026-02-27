<?php

namespace Iquesters\SmartMessenger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\Enums\Module;
use Iquesters\SmartMessenger\Config\SmartMessengerConf;
use Iquesters\SmartMessenger\Database\Seeders\SmartMessengerSeeder;
use Iquesters\SmartMessenger\Services\ContactService;
use Iquesters\Foundation\Services\QueueManager;
use Iquesters\SmartMessenger\Console\Commands\MonitorQueuesCommand;
use Iquesters\SmartMessenger\Console\ServeSchedulerManager;
use Iquesters\SmartMessenger\Listeners\JobProcessedListener;
use Iquesters\SmartMessenger\Listeners\JobFailedListener;
use Iquesters\SmartMessenger\Listeners\JobProcessingListener;
use Iquesters\SmartMessenger\Listeners\JobQueuedListener;
use Iquesters\SmartMessenger\Listeners\JobAttemptedListener;
use Iquesters\SmartMessenger\Listeners\JobExceptionOccurredListener;
use Iquesters\SmartMessenger\Listeners\JobPoppedListener;
use Iquesters\SmartMessenger\Listeners\JobPoppingListener;
use Iquesters\SmartMessenger\Listeners\JobQueueingListener;
use Iquesters\SmartMessenger\Listeners\JobReleasedAfterExceptionListener;
use Iquesters\SmartMessenger\Listeners\JobRetryRequestedListener;
use Iquesters\SmartMessenger\Listeners\JobTimedOutListener;
use Iquesters\SmartMessenger\Listeners\LoopingListener;
use Iquesters\SmartMessenger\Listeners\QueueBusyListener;
use Iquesters\SmartMessenger\Listeners\QueueFailedOverListener;
use Iquesters\SmartMessenger\Listeners\QueuePausedListener;
use Iquesters\SmartMessenger\Listeners\QueueResumedListener;
use Iquesters\SmartMessenger\Listeners\WorkerStartingListener;
use Iquesters\SmartMessenger\Listeners\WorkerStoppingListener;

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

            // ================================================================
            // AUTO-START SCHEDULER - COMMENTED OUT FOR PRODUCTION
            // ================================================================
            // In production/shared hosting: Use Queue Management UI instead
            // To re-enable for local development: Uncomment the line below
            // ================================================================
            // ServeSchedulerManager::register();
            // ================================================================
        }

        // Register job completion listener (successful jobs only)
        // Failed jobs are automatically stored in failed_jobs table by Laravel
        Event::listen(JobProcessed::class, JobProcessedListener::class);
        Event::listen(JobQueued::class, JobQueuedListener::class);
        Event::listen(JobProcessing::class, JobProcessingListener::class);
        Event::listen(JobFailed::class, JobFailedListener::class);
        Event::listen(JobAttempted::class, JobAttemptedListener::class);
        Event::listen(JobExceptionOccurred::class, JobExceptionOccurredListener::class);
        Event::listen(JobPopping::class, JobPoppingListener::class);
        Event::listen(JobPopped::class, JobPoppedListener::class);
        Event::listen(JobQueueing::class, JobQueueingListener::class);
        Event::listen(JobReleasedAfterException::class, JobReleasedAfterExceptionListener::class);
        Event::listen(JobRetryRequested::class, JobRetryRequestedListener::class);
        Event::listen(JobTimedOut::class, JobTimedOutListener::class);
        Event::listen(Looping::class, LoopingListener::class);
        Event::listen(QueueBusy::class, QueueBusyListener::class);
        Event::listen(QueueFailedOver::class, QueueFailedOverListener::class);
        Event::listen(QueuePaused::class, QueuePausedListener::class);
        Event::listen(QueueResumed::class, QueueResumedListener::class);
        Event::listen(WorkerStarting::class, WorkerStartingListener::class);
        Event::listen(WorkerStopping::class, WorkerStoppingListener::class);

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
     * DISABLED for local development to prevent connection issues
     * 
     * NOTE: This method is currently not being called. To re-enable:
     * 1. Uncomment ServeSchedulerManager::register() in boot() method
     * 2. Or call this method directly from boot()
     */
    protected function autoStartScheduler(): void
    {
        // Don't auto-start in local environment
        if (app()->environment('local')) {
            \Illuminate\Support\Facades\Log::info('Auto-start scheduler disabled in local environment');
            return;
        }

        // Only run in web/serve context, not in console commands
        if (!$this->app->runningInConsole() || $this->isServeCommand()) {
            // Start scheduler in background on first request
            $this->app->booted(function () {
                $lockFile = storage_path('framework/schedule-worker.lock');
                
                // Check if scheduler is already running
                if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 120) {
                    // Verify scheduler is not already running
                    if ($this->isSchedulerProcessRunning()) {
                        \Illuminate\Support\Facades\Log::info('Scheduler already running, skipping auto-start');
                        @file_put_contents($lockFile, time());
                        return;
                    }
                    
                    // Update lock file
                    @file_put_contents($lockFile, time());
                    
                    // Start scheduler in background
                    $this->startSchedulerWorker();
                }
            });
        }
    }

    /**
     * Check if scheduler process is already running
     */
    protected function isSchedulerProcessRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV | findstr "schedule:work"');
        } else {
            $output = shell_exec('ps aux | grep "schedule:work" | grep -v grep');
        }
        
        return !empty($output);
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
