<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;

// | Scenario                 | Result                |
// | ------------------------ | --------------------- |
// | No workflow_ids          | → Chatbot job runs    |
// | workflow_ids empty array | → Chatbot job runs    |
// | Workflow inactive        | → Ignored             |
// | Meta inactive            | → Ignored             |
// | Jobs missing             | → Chatbot job runs    |
// | Jobs valid               | → All run dynamically |

class NewMessageJob extends BaseJob
{
    protected Channel $channel;
    protected array $message;
    protected array $rawPayload;
    protected ?array $metadata;
    protected array $contacts;

    /**
     * Namespace where workflow jobs live
     */
    protected string $jobNamespace = 'Iquesters\\SmartMessenger\\Jobs\\MessageJobs\\';

    /**
     * Default fallback job (only hardcoded place allowed)
     */
    protected string $defaultJob = 'ForwardToChatbotJob';

    protected function initialize(...$arguments): void
    {
        [
            $channel,
            $message,
            $rawPayload,
            $metadata,
            $contacts
        ] = $arguments;

        $this->channel = $channel;
        $this->message = $message;
        $this->rawPayload = $rawPayload;
        $this->metadata = $metadata;
        $this->contacts = $contacts ?? [];
    }

    /**
     * Main orchestrator
     */
    public function process(): void
    {
        try {
            Log::info('Processing new message orchestration', [
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown'
            ]);

            /**
             * Step 1 — Save message
             */
            $saveMessageJob = new SaveMessageHelper(
                $this->channel,
                $this->message,
                $this->rawPayload,
                $this->metadata,
                $this->contacts
            );

            $result = $saveMessageJob->process();

            $savedMessage = $result['message'];
            $contact = $result['contact'];

            if (!$savedMessage) {
                Log::warning('Message could not be saved, stopping processing');
                return;
            }

            /**
             * Step 2 — Dispatch workflow jobs
             */
            $this->dispatchForwardingJobs($savedMessage, $contact);

        } catch (\Throwable $e) {

            Log::error('NewMessageJob failed', [
                'error' => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Dispatch jobs
     */
    protected function dispatchForwardingJobs($savedMessage, $contact): void
    {
        $jobsToRun = $this->resolveWorkflowJobs();

        /**
         * If nothing resolved → fallback default job
         */
        if (empty($jobsToRun)) {

            $defaultClass = $this->resolveJobClass($this->defaultJob);

            if ($defaultClass) {
                Log::info('Using fallback default workflow job', [
                    'job' => $defaultClass
                ]);

                $jobsToRun = [$defaultClass];
            }
        }

        foreach ($jobsToRun as $jobClass) {
            $jobClass::dispatch(
                $savedMessage,
                $this->rawPayload,
                $contact
            );
        }
    }

    /**
     * Resolve workflow jobs from channel → active workflows → active metas
     */
    protected function resolveWorkflowJobs(): array
    {
        $workflowIds = $this->channel->getMeta('workflow_ids');

        /**
         * If channel has no workflows configured → fallback later
         */
        if (!$workflowIds) {
            return [];
        }

        if (!is_array($workflowIds)) {
            $workflowIds = json_decode($workflowIds, true) ?? [];
        }

        if (empty($workflowIds)) {
            return [];
        }

        /**
         * Only ACTIVE workflows allowed
         */
        $activeWorkflowIds = DB::table('workflows')
            ->whereIn('id', $workflowIds)
            ->where('status', 'active')
            ->pluck('id');

        if ($activeWorkflowIds->isEmpty()) {
            return [];
        }

        /**
         * Fetch ACTIVE workflow metas
         */
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

                if (empty($job['name'])) {
                    continue;
                }

                $class = $this->resolveJobClass($job['name']);

                if ($class) {
                    $jobClasses[] = $class;
                }
            }
        }

        return array_unique($jobClasses);
    }

    /**
     * Reflection-based job resolver
     */
    protected function resolveJobClass(string $jobName): ?string
    {
        $class = $this->jobNamespace . $jobName;

        if (!class_exists($class)) {
            Log::warning("Workflow job class not found: {$class}");
            return null;
        }

        if (!is_subclass_of($class, BaseJob::class)) {
            Log::warning("Workflow job {$class} is not a valid BaseJob");
            return null;
        }

        return $class;
    }
}