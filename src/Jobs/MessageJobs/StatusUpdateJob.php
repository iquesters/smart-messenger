<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Iquesters\Foundation\Jobs\BaseJob;
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
        $this->logMethodStart($this->ctx([
            'count' => count($this->statuses),
        ]));

        try {
            $this->logInfo('Processing status updates' . $this->ctx([
                'count' => count($this->statuses)
            ]));

            $updatedCount = 0;

            foreach ($this->statuses as $status) {
                $messageId = $status['id'] ?? null;
                $newStatus = $status['status'] ?? null;

                if (!$messageId || !$newStatus) {
                    continue;
                }

                $message = Message::where('message_id', $messageId)->first();

                if ($message) {
                    $oldStatus = $message->status;

                    $message->update([
                        'status' => $newStatus,
                        'raw_response' => $status
                    ]);

                    $updatedCount++;

                    $this->logInfo('Message status updated' . $this->ctx([
                        'message_id' => $messageId,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus
                    ]));
                } else {
                    $this->logWarning('Message not found for status update' . $this->ctx([
                        'message_id' => $messageId
                    ]));
                }
            }

            $this->logInfo('Status updates completed' . $this->ctx([
                'total' => count($this->statuses),
                'updated' => $updatedCount
            ]));

        } catch (\Throwable $e) {
            $this->logError('StatusUpdateJob failed' . $this->ctx([
                'error' => $e->getMessage(),
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'count' => count($this->statuses),
            ]));
        }
    }
}
