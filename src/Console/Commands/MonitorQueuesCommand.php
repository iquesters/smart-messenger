<?php

namespace Iquesters\SmartMessenger\Console\Commands;

use Illuminate\Console\Command;
use Iquesters\Foundation\Services\QueueManager;

class MonitorQueuesCommand extends Command
{
    protected $signature = 'smart-messenger:monitor-queues';
    protected $description = 'Monitor and automatically start queue workers for pending jobs';

    public function handle(QueueManager $queueManager): int
    {
        $this->info('Monitoring queues for pending jobs...');

        $queueManager->processQueues();

        $stats = $queueManager->getQueueStats();

        if (empty($stats)) {
            $this->warn('No active queues found.');
            return 0;
        }

        $this->table(
            ['Queue', 'Waiting', 'Processing', 'Total', 'Workers', 'Max Workers'],
            collect($stats)->map(function ($stat) {
                return [
                    $stat['name'],
                    $stat['jobs']['waiting'],
                    $stat['jobs']['processing'],
                    $stat['jobs']['total'],
                    $stat['workers']['running'],
                    $stat['workers']['max']
                ];
            })
        );

        return 0;
    }
}