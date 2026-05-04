<?php

namespace Iquesters\SmartMessenger\Services;

use Throwable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Iquesters\SmartMessenger\Models\ChatSession;
use Iquesters\Foundation\System\Traits\Loggable;

class ChatSessionHandoverService
{
    use Loggable;

    public function __construct(
        protected HumanHandoverStateResolver $handoverStateResolver
    ) {
    }

    public function returnControlToBot(
        string $contactUid,
        string $chatbotIntegrationUid,
        $agentUserId,
        string $reason = 'agent_returned_control_to_bot'
    ): array {
        $context = [
            'contact_uid' => $contactUid,
            'chatbot_integration_uid' => $chatbotIntegrationUid,
            'agent_user_id' => $agentUserId,
            'route_decision' => 'returned_to_bot',
            'reason' => $reason,
        ];

        $this->logMethodStart('Return-to-bot requested' . $this->ctx($context));

        try {
            $result = DB::transaction(function () use ($contactUid, $chatbotIntegrationUid, $agentUserId, $reason, $context) {
                // TODO Phase 2: move all session mutations behind a dedicated owner with merge/version/audit support.
                $session = ChatSession::query()
                    ->where('contact_uid', $contactUid)
                    ->where('integration_id', $chatbotIntegrationUid)
                    ->where(function ($query) {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->orderByDesc('last_active_at')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$session) {
                    $exception = new ModelNotFoundException();
                    $exception->setModel(ChatSession::class, [$contactUid, $chatbotIntegrationUid]);
                    throw $exception;
                }

                $this->logInfo('Chat session row lock acquired' . $this->ctx($context + [
                    'chat_session_id' => $session->session_id,
                ]));

                $entries = $this->normalizeContextEntries($session->context_json, $session->session_id);
                $previousState = $this->handoverStateResolver->resolve($entries);
                $nowUtc = CarbonImmutable::now('UTC')->toIso8601String();
                $sinceUtc = $previousState['hand_over_time'] ?? $nowUtc;

                $entries[] = [
                    'role' => 'state',
                    'type' => 'ccx_state_snapshot',
                    'source' => 'laravel_agent_ui',
                    'created_at' => $nowUtc,
                    'ccx_state_snapshot' => [
                        'payload' => [
                            'human_handover' => [
                                'active' => false,
                                'since_utc' => $sinceUtc,
                                'ended_utc' => $nowUtc,
                                'reason' => $reason,
                                'status' => 'closed',
                                'ended_by' => 'agent',
                            ],
                        ],
                    ],
                ];

                $session->forceFill([
                    'context_json' => json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'last_active_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
                ])->save();

                $this->logInfo('Inactive handover state appended to chat session' . $this->ctx($context + [
                    'chat_session_id' => $session->session_id,
                    'hand_over_time' => $sinceUtc,
                    'ended_utc' => $nowUtc,
                ]));

                return [
                    'session_id' => $session->session_id,
                    'contact_uid' => $contactUid,
                    'chatbot_integration_uid' => $chatbotIntegrationUid,
                    'agent_user_id' => $agentUserId,
                    'hand_over_time' => $sinceUtc,
                    'ended_utc' => $nowUtc,
                    'reason' => $reason,
                    'route_decision' => 'returned_to_bot',
                ];
            });

            $this->logInfo('Return-to-bot completed successfully' . $this->ctx($context + [
                'chat_session_id' => $result['session_id'] ?? null,
                'ended_utc' => $result['ended_utc'] ?? null,
            ]));

            return $result;
        } catch (Throwable $e) {
            $this->logError('Return-to-bot failed' . $this->ctx($context + [
                'error' => $e->getMessage(),
            ]));

            throw $e;
        } finally {
            $this->logMethodEnd('Return-to-bot flow complete' . $this->ctx($context));
        }
    }

    private function normalizeContextEntries($contextJson, ?string $sessionId): array
    {
        if ($contextJson === null || $contextJson === '') {
            return [];
        }

        if (is_string($contextJson)) {
            try {
                $decoded = json_decode($contextJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $this->logWarning('Malformed context_json while appending return-to-bot state; resetting to empty array' . $this->ctx([
                    'chat_session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]));

                return [];
            }
        } elseif (is_object($contextJson)) {
            $decoded = json_decode(json_encode($contextJson), true) ?? [];
        } else {
            $decoded = $contextJson;
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
