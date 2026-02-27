<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\WorkerStopping;
use Iquesters\Foundation\System\Traits\Loggable;

class WorkerStoppingListener
{
    use Loggable;

    public function handle(WorkerStopping $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'WorkerStopping fired | status=%d',
                (int) $event->status
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

