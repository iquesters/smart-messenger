<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use App\Models\User;

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
        $this->logMethodStart($this->ctx([
            'channel_id' => $this->channel->id,
            'message_id' => $this->message['id'] ?? 'unknown',
        ]));

        try {
            $this->logInfo('Processing new message orchestration' . $this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown'
            ]));

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

            if ($this->routeAgentReply($savedMessage)) {
                return; // stop workflow execution
            }

            if (!$savedMessage) {
                $this->logWarning('Message could not be saved, stopping processing' . $this->ctx([
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->message['id'] ?? 'unknown',
                ]));
                return;
            }

            /**
             * Step 2 — Dispatch workflow jobs
             */
            $this->dispatchForwardingJobs($savedMessage, $contact);

        } catch (\Throwable $e) {

            $this->logError('NewMessageJob failed' . $this->ctx([
                'error' => $e->getMessage(),
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown',
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->message['id'] ?? 'unknown',
            ]));
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
                $this->logInfo('Using fallback default workflow job' . $this->ctx([
                    'job' => $defaultClass
                ]));

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
            $this->logWarning('Workflow job class not found' . $this->ctx([
                'job_class' => $class,
            ]));
            return null;
        }

        if (!is_subclass_of($class, BaseJob::class)) {
            $this->logWarning('Workflow job is not a valid BaseJob' . $this->ctx([
                'job_class' => $class,
            ]));
            return null;
        }

        return $class;
    }

    protected function detectAgentReply(): ?Message
    {
        $msg = $this->rawPayload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if (!$msg || empty($msg['context']['id'])) {
            return null;
        }

        return Message::where(
            'message_id',
            $msg['context']['id']
        )->first();
    }
    
    protected function routeAgentReply($savedMessage): bool
    {
        $this->logInfo('Checking if message is agent reply' . $this->ctx([
            'saved_message_id' => $savedMessage->id ?? null
        ]));

        $forwardedMessage = $this->detectAgentReply();

        if (!$forwardedMessage) {
            $this->logInfo('Not an agent reply: no forwarded message context found' . $this->ctx([
                'saved_message_id' => $savedMessage->id ?? null,
            ]));
            return false;
        }

        $this->logInfo('Forwarded message detected' . $this->ctx([
            'forwarded_message_id' => $forwardedMessage->id
        ]));

        $originalId = $forwardedMessage->getMeta('forwarded_from');

        if (!$originalId) {
            $this->logWarning('Forwarded message missing original reference' . $this->ctx([
                'forwarded_message_id' => $forwardedMessage->id
            ]));
            return false;
        }

        $originalMessage = Message::find($originalId);

        if (!$originalMessage) {
            $this->logWarning('Original customer message not found' . $this->ctx([
                'original_id' => $originalId
            ]));
            return false;
        }

        // 🔥 Detect agent user
        $agent = $this->detectAgentUser();

        if (!$agent) {
            $this->logWarning('Agent reply detected but no matching user found' . $this->ctx([
                'phone_from_payload' => $this->rawPayload['entry'][0]['changes'][0]['value']['messages'][0]['from'] ?? null
            ]));
        } else {
            $this->logInfo('Agent identified successfully' . $this->ctx([
                'agent_id' => $agent->id,
                'agent_phone' => $agent->phone
            ]));
        }

        $this->logInfo('Routing agent reply to customer' . $this->ctx([
            'agent_msg_id' => $savedMessage->id,
            'customer_msg_id' => $originalMessage->id,
            'to' => $originalMessage->from,
            'agent_id' => $agent?->id
        ]));

        SendWhatsAppReplyJob::dispatch(
            $savedMessage,
            [
                'type' => 'text',
                'text' => $savedMessage->content,
                'to_override' => $originalMessage->from,
                'created_by_override' => $agent?->id,
            ]
        );

        return true;
    }

    protected function detectAgentUser(): ?User
    {
        $msg = $this->rawPayload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if (!$msg) {
            $this->logWarning('Agent detection failed: message payload missing');
            return null;
        }

        $agentPhone = $msg['from'] ?? null;

        if (!$agentPhone) {
            $this->logWarning('Agent detection failed: phone missing in payload');
            return null;
        }

        $this->logInfo('Attempting agent lookup' . $this->ctx([
            'raw_phone' => $agentPhone
        ]));

        // normalize incoming phone
        $normalizedIncoming = preg_replace('/\D+/', '', $agentPhone);

        $this->logInfo('Normalized incoming phone' . $this->ctx([
            'normalized' => $normalizedIncoming
        ]));

        // fetch users and compare normalized phones
        $user = User::get()->first(function ($u) use ($normalizedIncoming) {
            $dbPhone = preg_replace('/\D+/', '', $u->phone);
            return $dbPhone === $normalizedIncoming;
        });

        if (!$user) {
            $this->logWarning('No user found for agent phone after normalization' . $this->ctx([
                'normalized_phone' => $normalizedIncoming
            ]));
            return null;
        }

        $this->logInfo('Agent user matched' . $this->ctx([
            'user_id' => $user->id,
            'phone' => $user->phone
        ]));

        return $user;
    }

}
