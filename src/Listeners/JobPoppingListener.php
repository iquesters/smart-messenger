<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobPopping;
use Iquesters\Foundation\System\Traits\Loggable;

class JobPoppingListener
{
    use Loggable;

    public function handle(JobPopping $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'JobPopping fired | connection=%s',
                (string) $event->connectionName
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

