<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
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

            // Get job data
            $jobData = [
                'uuid' => $payload['uuid'] ?? $event->job->getJobId(),
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'payload' => $event->job->getRawBody(),
                'response' => json_encode([
                    'status' => 'completed',
                    'attempts' => $payload['attempts'] ?? 1,
                    'completed_at' => now()->toDateTimeString(),
                ]),
                'completed_at' => now(),
            ];

            // Insert into completed_jobs table
            DB::table('completed_jobs')->insert($jobData);

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
}