<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Services\QueueManager;

class JobQueuedListener
{
    public function handle(JobQueued $event): void
    {
        Log::info('Job queued', [
            'connection' => $event->connectionName,
            'queue'      => $event->queue,
            // 'job'        => $event->job->resolveName(),
            // 'payload'    => $event->job->payload(),
        ]);
        app(QueueManager::class)->processQueues();
    }
}