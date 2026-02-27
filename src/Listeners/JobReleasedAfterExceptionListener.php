<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Cache;
use Iquesters\Foundation\Services\QueueManager;
use Iquesters\Foundation\System\Traits\Loggable;

class JobReleasedAfterExceptionListener
{
    use Loggable;

    public function handle(JobReleasedAfterException $event): void
{
    $this->logMethodStart();

    $connection = (string) $event->connectionName;
    $lock = Cache::lock('queue-processor-lock', 0.5);

    if (!$lock->get()) {
        $this->logDebug("Queue processor locked by another process (connection={$connection})");
        return;
    }

    try {
        $this->logWarning(sprintf(
            'JobReleasedAfterException fired | connection=%s',
            $connection
        ));

        $retryDelay = $event->job->payload()['delay'] ?? 10;

        $this->logInfo('Job released after exception - scheduling worker start', [
            'retry_delay_seconds' => $retryDelay,
        ]);

        // Sleep until the job's retry delay has passed, THEN spawn the worker
        // This runs in the listener (which is already in an async context)
        sleep((int) $retryDelay + 2);

        $this->logInfo('Retry delay elapsed - triggering queue processor');
        app(QueueManager::class)->processQueues();

    } finally {
        $lock->release();
        $this->logMethodEnd();
    }
}
}