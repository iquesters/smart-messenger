<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Iquesters\Foundation\Services\QueueManager;

class JobQueuedListener
{
    public function handle(JobQueued $event): void
    {
        $queueName = $event->job->queue ?? 'default';
        
        // Shorter lock duration (500ms) for more responsive processing
        $lock = Cache::lock('queue-processor-lock', 0.5);
        
        if (!$lock->get()) {
            Log::debug('Queue processor locked by another process', [
                'queue' => $queueName
            ]);
            return;
        }

        try {
            Log::info('Job queued - triggering queue processor', [
                'triggered_by_queue' => $queueName,
            ]);

            app(QueueManager::class)->processQueues();
        } finally {
            $lock->release();
        }
    }
}