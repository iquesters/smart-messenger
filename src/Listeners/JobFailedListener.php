<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

class JobFailedListener
{
    public function handle(JobFailed $event): void
    {
        Log::error('Job failed', [
            'connection' => $event->connectionName,
            'queue'      => $event->job->getQueue(),
            'job'        => $event->job->resolveName(),
            'attempts'   => $event->job->attempts(),
            'exception'  => $event->exception->getMessage(),
        ]);
    }
}