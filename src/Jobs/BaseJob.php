<?php

namespace Iquesters\SmartMessenger\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying
     */
    public int $backoff = 10;

    /**
     * Number of seconds the job can run before timing out
     */
    public int $timeout = 120;

    /**
     * The number of seconds to wait before retrying a job that encountered a deadlock.
     */
    public int $backoffDeadlock = 5;

    /**
     * Store the response data from job processing
     */
    protected mixed $jobResponse = null;
    
    final public function __construct(...$arguments)
    {
        // Use short class name as queue name
        $this->queue = (new \ReflectionClass(static::class))->getShortName();
        Log::debug('Job initialized', [
            'job_class' => static::class,
            'queue' => $this->queue
        ]);
        
        // Call child initializer
        $this->initialize(...$arguments);
    }
    
    /**
     * Child jobs implement this instead of a constructor
     */
    abstract protected function initialize(...$arguments): void;
    
    /**
     * Process webhook - must be implemented by child classes
     */
    abstract protected function process(): void;

    /**
     * Handle the job
     */
    final public function handle(): void
    {
        $this->beforeHandle();

        try {
            $this->process();
            $this->afterHandle();
            
        } catch (\Throwable $e) {
            Log::error('Job failed', [
                'job_class' => static::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($this->attempts() < $this->tries) {
                $this->onRetry($e);
            } else {
                $this->failed($e);
            }

            throw $e;
        }
    }
    
    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job failed permanently', [
            'job_class' => static::class,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Call child class hook if exists
        if (method_exists($this, 'onFailure')) {
            $this->onFailure($exception);
        }
    }

    /**
     * Called before job execution
     */
    protected function beforeHandle(): void
    {
        Log::debug('Job starting', [
            'job_class' => static::class,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts()
        ]);
    }

    /**
     * Called after successful job execution
     */
    protected function afterHandle(): void
    {
        Log::debug('Job completed successfully', [
            'job_class' => static::class,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'response' => $this->jobResponse
        ]);
    }

    /**
     * Called when job is being retried
     */
    protected function onRetry(\Throwable $exception): void
    {
        Log::warning('Job retry scheduled', [
            'job_class' => static::class,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'error' => $exception->getMessage(),
            'next_retry_in' => $this->backoff . ' seconds'
        ]);
    }

    /**
     * Set the response data (optional, for child classes)
     */
    protected function setResponse(mixed $response): void
    {
        $this->jobResponse = $response;
    }

    /**
     * Get the response data
     */
    public function getResponse(): mixed
    {
        return $this->jobResponse;
    }
}