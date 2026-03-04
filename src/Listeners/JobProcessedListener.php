<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Iquesters\Foundation\Models\CompletedJob;
use Iquesters\Foundation\System\Traits\Loggable;

class JobProcessedListener
{
    use Loggable;

    /**
     * Handle successful job completion
     */
    public function handle(JobProcessed $event): void
    {
        $this->logMethodStart();

        try {
            $payload = json_decode($event->job->getRawBody(), true);
            $completedAt = $this->currentDateTime();
            $uuid = $payload['uuid'] ?? $event->job->getJobId();
            $jobRecord = method_exists($event->job, 'getJobRecord')
                ? $event->job->getJobRecord()
                : null;
            $queuedAtTimestamp = $jobRecord->created_at ?? null;
            $availableAtTimestamp = $jobRecord->available_at ?? null;
            $reservedAtTimestamp = $jobRecord->reserved_at ?? null;

            $startedAtValue = Cache::pull(sprintf('queue-job-started-at:%s', $uuid));
            $startedAt = $startedAtValue
                ? now()->setTimestamp((int) $startedAtValue)
                : null;

            // Get job data
            $jobData = [
                'uuid' => $uuid,
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'payload' => $event->job->getRawBody(),
                'response' => json_encode([
                    'status' => 'completed',
                    'attempts' => $payload['attempts'] ?? 1,
                    'completed_at' => $completedAt->toDateTimeString(),
                ]),
                'queued_at' => $this->toDateTime($queuedAtTimestamp),
                'available_at' => $this->toDateTime($availableAtTimestamp),
                'reserved_at' => $this->toDateTime($reservedAtTimestamp),
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ];

            // Persist through Eloquent so custom date fields use model casts.
            CompletedJob::create($jobData);

            $this->logDebug(sprintf(
                'Job completion recorded | uuid=%s queue=%s',
                $jobData['uuid'],
                $jobData['queue']
            ));
        } catch (\Throwable $e) {
            $this->logError(sprintf(
                'Failed to record job completion | job_id=%s | %s',
                $event->job->getJobId(),
                $e->getMessage()
            ));

            throw $e; // optional but recommended
        } finally {
            $this->logMethodEnd();
        }
    }

    private function toDateTime($timestamp)
    {
        return $timestamp ? now()->setTimestamp((int) $timestamp) : null;
    }

    private function currentDateTime()
    {
        return now();
    }
}