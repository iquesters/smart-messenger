<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobRetryRequested;
use Iquesters\Foundation\System\Traits\Loggable;

class JobRetryRequestedListener
{
    use Loggable;

    public function handle(JobRetryRequested $event): void
    {
        $this->logMethodStart();

        try {
            $this->logWarning('JobRetryRequested fired');
        } finally {
            $this->logMethodEnd();
        }
    }
}

