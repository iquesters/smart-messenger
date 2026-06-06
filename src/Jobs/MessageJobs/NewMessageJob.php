<?php

namespace Iquesters\SmartMessenger\Jobs\MessageJobs;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Jobs\BaseJob;
use Iquesters\Integration\Models\Integration;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ChatbotIntegrationResolverService;

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
    protected string $platform;

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
        $this->channel = $arguments[0];
        $this->message = $arguments[1];
        $this->rawPayload = $arguments[2];
        $this->metadata = $arguments[3] ?? null;
        $this->contacts = $arguments[4] ?? [];
        $this->platform = $this->detectPlatform();
    }

    /**
     * Main orchestrator
     */
    public function process(): void
    {
        $this->logMethodStart($this->ctx([
            'channel_id' => $this->channel->id,
            'message_id' => $this->getInboundMessageIdentifier(),
            'platform' => $this->platform,
        ]));

        try {
            $this->logInfo('Processing new message orchestration' . $this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->getInboundMessageIdentifier(),
                'platform' => $this->platform,
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
            $isDuplicate = (bool) ($result['is_duplicate'] ?? false);

            if ($isDuplicate) {
                $this->logInfo('Duplicate inbound message detected, skipping downstream dispatch' . $this->ctx([
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->getInboundMessageIdentifier(),
                    'saved_message_id' => $savedMessage->id ?? null,
                    'platform' => $this->platform,
                ]));
                return;
            }

            if ($this->shouldRouteAgentReply() && $this->routeAgentReply($savedMessage)) {
                return; // stop workflow execution
            }

            if (!$savedMessage) {
                $this->logWarning('Message could not be saved, stopping processing' . $this->ctx([
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->getInboundMessageIdentifier(),
                    'platform' => $this->platform,
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
                'message_id' => $this->getInboundMessageIdentifier(),
                'platform' => $this->platform,
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd($this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->getInboundMessageIdentifier(),
                'platform' => $this->platform,
            ]));
        }
    }

    /**
     * Dispatch jobs
     */
    protected function dispatchForwardingJobs($savedMessage, $contact): void
    {
        $jobsToRun = $this->resolveWorkflowJobs();
        $defaultClass = $this->resolveJobClass($this->defaultJob);

        /**
         * If nothing resolved → fallback default job
         */
        if (empty($jobsToRun)) {

            if ($defaultClass) {
                $this->logInfo('Using fallback default workflow job' . $this->ctx([
                    'job' => $defaultClass
                ]));

                $jobsToRun = [$defaultClass];
            }
        }

        foreach ($jobsToRun as $jobClass) {
            if ($jobClass === $defaultClass && !$this->shouldDispatchChatbotJob()) {
                $this->logInfo('Skipping chatbot dispatch for this inbound message' . $this->ctx([
                    'job' => $jobClass,
                    'channel_id' => $this->channel->id,
                    'message_id' => $this->getInboundMessageIdentifier(),
                ]));
                continue;
            }

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

    protected function shouldDispatchChatbotJob(): bool
    {
        $integration = $this->resolveActiveChatbotIntegration();

        if (!$integration) {
            return true;
        }

        $isEnabled = filter_var(
            (string) $integration->getMeta(Constants::ALLOW_INTERNAL_TESTING, 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$isEnabled) {
            return true;
        }

        return $this->shouldAllowInternalTestingRoute($integration);
    }

    protected function shouldAllowInternalTestingRoute(?Integration $integration = null): bool
    {
        $organisation = $this->channel->organisations()->first();

        if (!$organisation) {
            return false;
        }

        $integration ??= $this->resolveActiveChatbotIntegration();

        if (!$integration) {
            return false;
        }

        $incomingPhone = $this->normalizePhoneNumber($this->message['from'] ?? null);

        if ($incomingPhone === '') {
            $this->logInfo('Internal testing skipped because inbound phone is missing' . $this->ctx([
                'channel_id' => $this->channel->id,
                'message_id' => $this->getInboundMessageIdentifier(),
            ]));
            return false;
        }

        $matchedUser = User::query()
            ->whereNotNull('phone')
            ->whereHas('organisations', function ($query) use ($organisation) {
                $query->where('organisations.id', $organisation->id);
            })
            ->get(['id', 'phone'])
            ->first(function (User $user) use ($incomingPhone) {
                return $this->normalizePhoneNumber((string) $user->phone) === $incomingPhone;
            });

        if (!$matchedUser) {
            $this->logInfo('Inbound phone did not match any organisation user for internal testing' . $this->ctx([
                'channel_id' => $this->channel->id,
                'organisation_id' => $organisation->id,
                'integration_id' => $integration->id,
            ]));
            return false;
        }

        $this->logInfo('Inbound phone matched organisation user for internal testing' . $this->ctx([
            'channel_id' => $this->channel->id,
            'organisation_id' => $organisation->id,
            'integration_id' => $integration->id,
            'matched_user_id' => $matchedUser->id,
        ]));

        return true;
    }

    protected function resolveActiveChatbotIntegration(): ?Integration
    {
        $integrationId = app(ChatbotIntegrationResolverService::class)
            ->resolveIdFromChannel($this->channel);

        if (!$integrationId) {
            return null;
        }

        return Integration::query()
            ->with('metas')
            ->find($integrationId);
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

    protected function detectPlatform(): string
    {
        return isset($this->message['message_id']) ? 'telegram' : 'whatsapp';
    }

    protected function getInboundMessageIdentifier(): string
    {
        return (string) ($this->message['id'] ?? $this->message['message_id'] ?? 'unknown');
    }

    protected function shouldRouteAgentReply(): bool
    {
        return $this->platform === 'whatsapp';
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

        $payload = $this->buildAgentReplyPayload($savedMessage);
        if (!$payload) {
            $this->logWarning('Agent reply payload could not be built' . $this->ctx([
                'agent_msg_id' => $savedMessage->id,
                'message_type' => $savedMessage->message_type,
            ]));
            return false;
        }

        $payload['to_override'] = $originalMessage->from;
        $payload['created_by_override'] = $agent?->id;

        SendWhatsAppReplyJob::dispatch($savedMessage, $payload);

        return true;
    }

    protected function buildAgentReplyPayload(Message $savedMessage): ?array
    {
        if ($savedMessage->message_type === 'text') {
            return [
                'type' => 'text',
                'text' => $savedMessage->content,
            ];
        }

        if ($savedMessage->message_type === 'image') {
            $mediaPath = $savedMessage->getMeta('media_path');
            $mediaUrl = $savedMessage->getMeta('media_url');
            $mimeType = $savedMessage->getMeta('media_mime_type');
            $size = $savedMessage->getMeta('media_size');

            if (!$mediaPath || !$mimeType) {
                $this->logWarning('Agent image reply missing stored media meta' . $this->ctx([
                    'agent_msg_id' => $savedMessage->id,
                    'has_path' => !empty($mediaPath),
                    'has_mime' => !empty($mimeType),
                ]));
                return null;
            }

            $caption = '';
            $content = json_decode($savedMessage->content, true);
            if (is_array($content) && array_key_exists('caption', $content)) {
                $caption = (string) ($content['caption'] ?? '');
            }

            $caption = $this->stripForwardedCaptionPrefix($caption);

            return [
                'type' => 'image',
                'caption' => $caption,
                'stored_media' => [
                    'driver' => $savedMessage->getMeta('media_driver') ?? 'local',
                    'path' => $mediaPath,
                    'url' => $mediaUrl,
                    'mime_type' => $mimeType,
                    'size' => $size,
                ],
            ];
        }

        return [
            'type' => 'text',
            'text' => $savedMessage->content,
        ];
    }

    protected function stripForwardedCaptionPrefix(string $caption): string
    {
        $caption = trim($caption);

        if ($caption === '') {
            return '';
        }

        if (!preg_match('/^Forwarded from\s*\d+/i', $caption)) {
            return $caption;
        }

        $parts = preg_split("/\r?\n\r?\n/", $caption, 2);
        if (is_array($parts) && count($parts) === 2) {
            return trim($parts[1]);
        }

        return '';
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
        $normalizedIncoming = $this->normalizePhoneNumber($agentPhone);

        $this->logInfo('Normalized incoming phone' . $this->ctx([
            'normalized' => $normalizedIncoming
        ]));

        // fetch users and compare normalized phones
        $user = User::get()->first(function ($u) use ($normalizedIncoming) {
            $dbPhone = $this->normalizePhoneNumber($u->phone);
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

    protected function normalizePhoneNumber(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

}
