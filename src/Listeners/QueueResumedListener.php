<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\QueueResumed;
use Iquesters\Foundation\System\Traits\Loggable;

class QueueResumedListener
{
    use Loggable;

    public function handle(QueueResumed $event): void
    {
        $this->logMethodStart();

        try {
            $this->logInfo(sprintf(
                'QueueResumed fired | connection=%s queue=%s',
                (string) $event->connection,
                (string) $event->queue
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

