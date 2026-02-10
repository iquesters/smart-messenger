<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;
use Iquesters\Foundation\Services\QueueManager;
use Iquesters\Foundation\System\Traits\Loggable;

class JobQueuedListener
{
    use Loggable;

    public function handle(JobQueued $event): void
    {
        $this->logMethodStart();

        $queueName = $event->job->queue ?? 'default';

        // Shorter lock duration (500ms) for more responsive processing
        $lock = Cache::lock('queue-processor-lock', 0.5);

        if (!$lock->get()) {
            $this->logDebug("Queue processor locked by another process (queue={$queueName})");
            return;
        }

        try {
            $this->logInfo("Job queued - triggering queue processor (queue={$queueName})");

            app(QueueManager::class)->processQueues();
        } catch (\Throwable $e) {
            $this->logError('Queue processing failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
            $this->logMethodEnd();
        }
    }
}