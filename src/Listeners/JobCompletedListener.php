<?php

namespace Iquesters\SmartMessenger\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobCompletedListener
{
    /**
     * Handle successful job completion
     */
    public function handle(JobProcessed $event): void
    {
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
                    'completed_at' => now()->toDateTimeString()
                ]),
                'completed_at' => now(),
            ];

            // Insert into completed_jobs table
            DB::table('completed_jobs')->insert($jobData);

            Log::debug('Job completion recorded', [
                'uuid' => $jobData['uuid'],
                'queue' => $jobData['queue']
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to record job completion', [
                'error' => $e->getMessage(),
                'job_id' => $event->job->getJobId()
            ]);
        }
    }
}