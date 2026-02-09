<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

class JobProcessingListener
{
    public function handle(JobProcessing $event): void
    {
        Log::info('Job processing started', [
            'connection' => $event->connectionName,
            'queue'      => $event->job->getQueue(),
            'job'        => $event->job->resolveName(),
            'attempts'   => $event->job->attempts(),
        ]);
    }
}