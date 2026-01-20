<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Message;

class StatusUpdateJob extends BaseJob
{
    protected array $statuses;

    /**
     * Initialize the job with statuses
     */
    protected function initialize(...$arguments): void
    {
        [$statuses] = $arguments;
        $this->statuses = $statuses;
    }
    /**
     * Handle the job
     */
    public function process(): void
    {
        try {
            Log::info('Processing status updates', [
                'count' => count($this->statuses)
            ]);

            $updatedCount = 0;

            foreach ($this->statuses as $status) {
                $messageId = $status['id'] ?? null;
                $newStatus = $status['status'] ?? null;

                if (!$messageId || !$newStatus) {
                    continue;
                }

                $message = Message::where('message_id', $messageId)->first();

                if ($message) {
                    $message->update([
                        'status' => $newStatus,
                        'raw_response' => $status
                    ]);

                    $updatedCount++;

                    Log::info('Message status updated', [
                        'message_id' => $messageId,
                        'old_status' => $message->status,
                        'new_status' => $newStatus
                    ]);
                } else {
                    Log::warning('Message not found for status update', [
                        'message_id' => $messageId
                    ]);
                }
            }

            Log::info('Status updates completed', [
                'total' => count($this->statuses),
                'updated' => $updatedCount
            ]);

        } catch (\Throwable $e) {
            Log::error('StatusUpdateJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}