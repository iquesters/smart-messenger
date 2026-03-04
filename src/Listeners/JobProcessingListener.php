<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Iquesters\Foundation\System\Traits\Loggable;

class JobProcessingListener
{
    use Loggable;

    public function handle(JobProcessing $event): void
    {
        $this->logMethodStart();

        try {
            $payload = json_decode($event->job->getRawBody(), true);
            $uuid = $payload['uuid'] ?? $event->job->getJobId();
            $startedAt = now();

            Cache::put(
                sprintf('queue-job-started-at:%s', $uuid),
                $startedAt->getTimestamp(),
                now()->addDay()
            );

            $this->logInfo(sprintf(
                'Job processing started | connection=%s queue=%s job=%s attempts=%d',
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->resolveName(),
                $event->job->attempts()
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}