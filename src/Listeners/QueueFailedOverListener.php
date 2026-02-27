<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\QueueFailedOver;
use Iquesters\Foundation\System\Traits\Loggable;

class QueueFailedOverListener
{
    use Loggable;

    public function handle(QueueFailedOver $event): void
    {
        $this->logMethodStart();

        try {
            $this->logError(sprintf(
                'QueueFailedOver fired | connection=%s | %s',
                (string) ($event->connectionName ?? 'unknown'),
                $event->exception->getMessage()
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

