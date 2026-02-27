<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\Looping;
use Iquesters\Foundation\System\Traits\Loggable;

class LoopingListener
{
    use Loggable;

    public function handle(Looping $event): void
    {
        $this->logMethodStart();

        try {
            $this->logDebug(sprintf(
                'Looping fired | connection=%s queue=%s',
                (string) $event->connectionName,
                (string) ($event->queue ?? 'default')
            ));
        } finally {
            $this->logMethodEnd();
        }
    }
}

