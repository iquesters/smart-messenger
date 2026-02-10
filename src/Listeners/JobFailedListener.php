<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Iquesters\Foundation\System\Traits\Loggable;

class JobFailedListener
{
    use Loggable;

    public function handle(JobFailed $event): void
    {
        $this->logMethodStart();

        try {
            $this->logError(sprintf(
                'Job failed | connection=%s queue=%s job=%s attempts=%d | %s',
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->resolveName(),
                $event->job->attempts(),
                $event->exception->getMessage(),
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}