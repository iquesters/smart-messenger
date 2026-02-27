<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\QueuePaused;
use Iquesters\Foundation\System\Traits\Loggable;

class QueuePausedListener
{
    use Loggable;

    public function handle(QueuePaused $event): void
    {
        $this->logMethodStart();

        try {
            $this->logWarning(sprintf(
                'QueuePaused fired | connection=%s queue=%s',
                (string) $event->connection,
                (string) $event->queue
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

