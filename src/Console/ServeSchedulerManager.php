<?php

namespace Iquesters\SmartMessenger\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class ServeSchedulerManager
{
    private static $schedulerPid = null;
    private static $lockFile = null;

    /**
     * Register event listeners for serve command
     */
    public static function register(): void
    {
        // Listen for serve command starting
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'serve') {
                self::onServeStart();
            }
        });

        // Listen for serve command finishing
        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if ($event->command === 'serve') {
                self::onServeStop();
            }
        });

        // Register shutdown handler for Ctrl+C
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [self::class, 'onServeStop']);
            pcntl_signal(SIGTERM, [self::class, 'onServeStop']);
        }

        // Register PHP shutdown function
        register_shutdown_function([self::class, 'cleanup']);
    }

    /**
     * Called when serve command starts
     */
    public static function onServeStart(): void
    {
        // Clean up any previous lock files
        self::$lockFile = storage_path('framework/schedule-worker.lock');
        
        if (file_exists(self::$lockFile)) {
            @unlink(self::$lockFile);
        }

        // Small delay to let server start
        usleep(500000); // 500ms

        // Start scheduler in background
        self::startScheduler();

        echo "\n";
        echo "✓ Queue scheduler started automatically\n";
        echo "✓ Press Ctrl+C to stop both server and scheduler\n";
        echo "\n";
    }

    /**
     * Start the scheduler process
     */
    private static function startScheduler(): void
    {
        $cmd = sprintf('php %s/artisan schedule:work', base_path());

        if (PHP_OS_FAMILY === 'Windows') {
            // Start detached process on Windows
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];

            $process = proc_open(
                'start /B ' . $cmd,
                $descriptors,
                $pipes,
                base_path()
            );

            if (is_resource($process)) {
                // Get PID (approximate on Windows)
                $status = proc_get_status($process);
                self::$schedulerPid = $status['pid'] ?? null;

                // Store PID in lock file
                @file_put_contents(self::$lockFile, json_encode([
                    'pid' => self::$schedulerPid,
                    'started_at' => time()
                ]));

                // Close pipes
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        } else {
            // Linux/Mac
            exec($cmd . ' > /dev/null 2>&1 & echo $!', $output);
            self::$schedulerPid = !empty($output[0]) ? (int) $output[0] : null;

            // Store PID in lock file
            @file_put_contents(self::$lockFile, json_encode([
                'pid' => self::$schedulerPid,
                'started_at' => time()
            ]));
        }

        Log::info('Scheduler auto-started', ['pid' => self::$schedulerPid]);
    }

    /**
     * Called when serve command stops
     */
    public static function onServeStop(): void
    {
        echo "\n";
        echo "Stopping scheduler...\n";

        self::stopScheduler();

        echo "✓ Scheduler stopped\n";
        echo "✓ All services stopped\n";
    }

    /**
     * Stop the scheduler process
     */
    private static function stopScheduler(): void
    {
        // Try to read PID from lock file if not in memory
        if (!self::$schedulerPid && self::$lockFile && file_exists(self::$lockFile)) {
            $data = json_decode(file_get_contents(self::$lockFile), true);
            self::$schedulerPid = $data['pid'] ?? null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Kill all schedule:work processes on Windows
            exec('taskkill /F /FI "IMAGENAME eq php.exe" /FI "WINDOWTITLE eq *schedule:work*" 2>nul');
            
            // Also try with tasklist filtering
            exec('wmic process where "commandline like \'%schedule:work%\'" delete 2>nul');
        } else {
            // Linux/Mac
            if (self::$schedulerPid) {
                // Try graceful shutdown first
                posix_kill(self::$schedulerPid, SIGTERM);
                sleep(1);
                
                // Force kill if still running
                posix_kill(self::$schedulerPid, SIGKILL);
            }
            
            // Also kill by process name as backup
            exec("pkill -f 'schedule:work'");
        }

        // Clean up lock file
        if (self::$lockFile && file_exists(self::$lockFile)) {
            @unlink(self::$lockFile);
        }

        Log::info('Scheduler stopped', ['pid' => self::$schedulerPid]);
        self::$schedulerPid = null;
    }

    /**
     * Cleanup on script termination
     */
    public static function cleanup(): void
    {
        // Only cleanup if we started a scheduler
        if (self::$schedulerPid || (self::$lockFile && file_exists(self::$lockFile))) {
            self::stopScheduler();
        }
    }

    /**
     * Check if scheduler is running
     */
    public static function isSchedulerRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" /NH | findstr /I "php.exe"');
            $processes = explode("\n", trim($output));
            
            foreach ($processes as $process) {
                if (stripos($process, 'php.exe') !== false) {
                    // Check if it's running schedule:work
                    $pid = preg_replace('/\s+/', ' ', trim($process));
                    $parts = explode(' ', $pid);
                    if (isset($parts[1])) {
                        $checkCmd = 'wmic process where ProcessId=' . $parts[1] . ' get CommandLine 2>nul';
                        $cmdLine = shell_exec($checkCmd);
                        if (stripos($cmdLine, 'schedule:work') !== false) {
                            return true;
                        }
                    }
                }
            }
            return false;
        } else {
            $output = shell_exec('ps aux | grep "schedule:work" | grep -v grep');
            return !empty($output);
        }
    }
}