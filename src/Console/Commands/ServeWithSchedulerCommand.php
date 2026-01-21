<?php

// File: src/Console/Commands/ServeWithSchedulerCommand.php

namespace Iquesters\SmartMessenger\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ServeWithSchedulerCommand extends Command
{
    protected $signature = 'smart-messenger:serve 
                            {--host=127.0.0.1 : The host address to serve the application on}
                            {--port=8000 : The port to serve the application on}';
    
    protected $description = 'Start Laravel server with automatic queue scheduler';

    private $processes = [];

    public function handle(): int
    {
        $this->info('Starting Laravel development server with queue scheduler...');

        // Start the Laravel development server
        $this->startServer();

        // Start the scheduler
        $this->startScheduler();

        $this->info('');
        $this->info('Server running on http://' . $this->option('host') . ':' . $this->option('port'));
        $this->info('Queue scheduler is running in the background');
        $this->info('Press Ctrl+C to stop');

        // Keep the command running
        $this->waitForProcesses();

        return 0;
    }

    private function startServer(): void
    {
        $host = $this->option('host');
        $port = $this->option('port');

        if (PHP_OS_FAMILY === 'Windows') {
            $process = Process::fromShellCommandline(
                "php artisan serve --host={$host} --port={$port}"
            );
        } else {
            $process = Process::fromShellCommandline(
                "php artisan serve --host={$host} --port={$port}"
            );
        }

        $process->setTimeout(null);
        $process->start(function ($type, $buffer) {
            echo $buffer;
        });

        $this->processes[] = $process;
    }

    private function startScheduler(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = Process::fromShellCommandline('php artisan schedule:work');
        } else {
            $process = Process::fromShellCommandline('php artisan schedule:work');
        }

        $process->setTimeout(null);
        $process->start(function ($type, $buffer) {
            if ($this->output->isVerbose()) {
                echo $buffer;
            }
        });

        $this->processes[] = $process;
    }

    private function waitForProcesses(): void
    {
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stopProcesses']);
            pcntl_signal(SIGINT, [$this, 'stopProcesses']);
        }

        // Wait for all processes
        foreach ($this->processes as $process) {
            $process->wait();
        }
    }

    public function stopProcesses(): void
    {
        $this->info("\nStopping all processes...");

        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        exit(0);
    }
}