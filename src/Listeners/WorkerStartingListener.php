<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\WorkerStarting;
use Iquesters\Foundation\System\Traits\Loggable;

class WorkerStartingListener
{
    use Loggable;

    public function handle(WorkerStarting $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'WorkerStarting fired | connection=%s queue=%s',
                (string) $event->connectionName,
                (string) ($event->queue ?? 'default')
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

