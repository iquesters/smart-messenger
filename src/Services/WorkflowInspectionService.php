<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\SmartMessenger\Models\Channel;

class WorkflowInspectionService
{
    use Loggable;

    protected string $jobNamespace = 'Iquesters\\SmartMessenger\\Jobs\\MessageJobs\\';

    public function workflowContainsJob(?Channel $channel, string $jobName): bool
    {
        $context = [
            'channel_id' => $channel?->id,
            'channel_uid' => $channel?->uid,
            'job_name' => $jobName,
        ];

        $this->logMethodStart('Inspecting workflow jobs' . $this->ctx($context));

        try {
            if (!$channel) {
                $this->logWarning('Workflow inspection skipped because channel is missing' . $this->ctx($context));
                return false;
            }

            $resolvedJobs = $this->resolveWorkflowJobs($channel);
            $targetClass = $this->resolveJobClass($jobName);
            $containsJob = $targetClass !== null && in_array($targetClass, $resolvedJobs, true);

            $this->logInfo('Workflow inspection result' . $this->ctx($context + [
                'resolved_jobs' => $resolvedJobs,
                'contains_job' => $containsJob,
            ]));

            return $containsJob;
        } finally {
            $this->logMethodEnd('Workflow inspection complete' . $this->ctx($context));
        }
    }

    private function resolveWorkflowJobs(Channel $channel): array
    {
        $workflowIds = $channel->getMeta('workflow_ids');

        if (!$workflowIds) {
            return [];
        }

        if (!is_array($workflowIds)) {
            $workflowIds = json_decode($workflowIds, true) ?? [];
        }

        if (empty($workflowIds)) {
            return [];
        }

        $activeWorkflowIds = DB::table('workflows')
            ->whereIn('id', $workflowIds)
            ->where('status', 'active')
            ->pluck('id');

        if ($activeWorkflowIds->isEmpty()) {
            return [];
        }

        $metas = DB::table('workflow_metas')
            ->whereIn('ref_parent', $activeWorkflowIds)
            ->where('meta_key', 'workflow_jobs')
            ->where('status', 'active')
            ->pluck('meta_value');

        $jobClasses = [];

        foreach ($metas as $metaJson) {
            $jobs = json_decode($metaJson, true);

            if (!is_array($jobs)) {
                continue;
            }

            foreach ($jobs as $job) {
                $jobName = $job['name'] ?? null;
                if (!$jobName) {
                    continue;
                }

                $class = $this->resolveJobClass($jobName);
                if ($class) {
                    $jobClasses[] = $class;
                }
            }
        }

        return array_values(array_unique($jobClasses));
    }

    private function resolveJobClass(string $jobName): ?string
    {
        $class = $this->jobNamespace . $jobName;

        if (!class_exists($class)) {
            return null;
        }

        if (!is_subclass_of($class, BaseJob::class)) {
            return null;
        }

        return $class;
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
