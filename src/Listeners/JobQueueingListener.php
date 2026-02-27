<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobQueueing;
use Iquesters\Foundation\System\Traits\Loggable;

class JobQueueingListener
{
    use Loggable;

    public function handle(JobQueueing $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'JobQueueing fired | connection=%s queue=%s',
                (string) $event->connectionName,
                (string) ($event->queue ?? 'default')
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

