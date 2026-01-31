<?php

namespace Iquesters\SmartMessenger\Jobs;

use Iquesters\Foundation\Jobs\BaseJob;
use Illuminate\Support\Facades\Log;

abstract class WHJob extends BaseJob
{
    /**
     * Webhook payload
     */
    protected array $payload;

    /**
     * Channel UID
     */
    protected string $channelUid;

    protected function initialize(...$arguments): void
    {
        [$payload, $channelUid] = $arguments;
        $this->payload = $payload;
        $this->channelUid = $channelUid;
    }
    
    /**
     * Hook for child classes to handle failures
     */
    protected function onFailure(\Throwable $exception): void
    {
        Log::error('Webhook processing failed permanently', [
            'channel_uid' => $this->channelUid,
            'error' => $exception->getMessage()
        ]);
    }
}