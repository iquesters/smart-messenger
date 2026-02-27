<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\QueueBusy;
use Iquesters\Foundation\System\Traits\Loggable;

class QueueBusyListener
{
    use Loggable;

    public function handle(QueueBusy $event): void
    {
        $this->logMethodStart();

        try {
            $this->logWarning(sprintf(
                'QueueBusy fired | connection=%s queue=%s size=%d',
                (string) $event->connection,
                (string) $event->queue,
                (int) $event->size
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

