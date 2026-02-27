<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobPopped;
use Iquesters\Foundation\System\Traits\Loggable;

class JobPoppedListener
{
    use Loggable;

    public function handle(JobPopped $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'JobPopped fired | connection=%s',
                (string) $event->connectionName
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

