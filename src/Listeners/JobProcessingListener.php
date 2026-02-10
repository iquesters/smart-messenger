<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Iquesters\Foundation\System\Traits\Loggable;

class JobProcessingListener
{
    use Loggable;

    public function handle(JobProcessing $event): void
    {
        $this->logMethodStart();

        try {
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