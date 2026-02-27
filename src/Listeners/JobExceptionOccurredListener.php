<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Iquesters\Foundation\System\Traits\Loggable;

class JobExceptionOccurredListener
{
    use Loggable;

    public function handle(JobExceptionOccurred $event): void
    {
        $this->logMethodStart();

        try {
            $this->logWarning(sprintf(
                'JobExceptionOccurred fired | connection=%s | %s',
                (string) $event->connectionName,
                $event->exception->getMessage()
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

