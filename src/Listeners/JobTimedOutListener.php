<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobTimedOut;
use Iquesters\Foundation\System\Traits\Loggable;

class JobTimedOutListener
{
    use Loggable;

    public function handle(JobTimedOut $event): void
    {
        $this->logMethodStart();

        try {
            $this->logError(sprintf(
                'JobTimedOut fired | connection=%s',
                (string) $event->connectionName
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

