<?php

namespace Iquesters\SmartMessenger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AutoStartScheduler
{
    /**
     * Handle an incoming request and auto-start scheduler if needed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check on first request or every 2 minutes
        $lockFile = storage_path('framework/schedule-worker.lock');
        
        if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 120) {
            $this->ensureSchedulerRunning($lockFile);
        }

        return $next($request);
    }

    /**
     * Ensure scheduler is running
     */
    protected function ensureSchedulerRunning(string $lockFile): void
    {
        // Check if schedule:work is already running
        if ($this->isSchedulerRunning()) {
            // Update lock file timestamp
            @file_put_contents($lockFile, time());
            return;
        }

        // Start scheduler worker
        $this->startScheduler();
        
        // Create/update lock file
        @file_put_contents($lockFile, time());
        
        Log::info('Scheduler auto-started via middleware');
    }

    /**
     * Check if scheduler process is running
     */
    protected function isSchedulerRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = shell_exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV | findstr "schedule:work"');
        } else {
            $result = shell_exec('ps aux | grep "schedule:work" | grep -v grep');
        }

        return !empty($result);
    }

    /**
     * Start scheduler in background
     */
    protected function startScheduler(): void
    {
        $cmd = sprintf('php %s/artisan schedule:work', base_path());
        
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B " . $cmd . " > NUL 2>&1", "r"));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
    }
}