<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobAttempted;
use Iquesters\Foundation\System\Traits\Loggable;

class JobAttemptedListener
{
    use Loggable;

    public function handle(JobAttempted $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'JobAttempted fired | connection=%s exception_occurred=%s',
                (string) $event->connectionName,
                $event->exceptionOccurred ? 'true' : 'false'
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

