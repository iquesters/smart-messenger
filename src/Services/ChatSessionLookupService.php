<?php

namespace Iquesters\SmartMessenger\Services;

use Throwable;
use Iquesters\SmartMessenger\Models\ChatSession;
use Iquesters\Foundation\System\Traits\Loggable;

class ChatSessionLookupService
{
    use Loggable;

    public function findLatestActive(?string $contactUid, ?string $chatbotIntegrationUid): ?ChatSession
    {
        $context = [
            'contact_uid' => $contactUid,
            'chatbot_integration_uid' => $chatbotIntegrationUid,
        ];

        $this->logMethodStart('Looking up latest active chat session' . $this->ctx($context));

        try {
            if (empty($contactUid) || empty($chatbotIntegrationUid)) {
                $this->logWarning('Skipping chat session lookup because identifiers are incomplete' . $this->ctx($context));
                return null;
            }

            $session = ChatSession::query()
                ->where('contact_uid', $contactUid)
                ->where('integration_id', $chatbotIntegrationUid)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('last_active_at')
                ->orderByDesc('created_at')
                ->first();

            if (!$session) {
                $this->logInfo('No active chat session found' . $this->ctx($context));
                return null;
            }

            $this->logInfo('Active chat session found' . $this->ctx($context + [
                'session_id' => $session->session_id,
            ]));

            return $session;
        } catch (Throwable $e) {
            $this->logWarning('Chat session lookup failed; caller will fail open' . $this->ctx($context + [
                'error' => $e->getMessage(),
            ]));

            return null;
        } finally {
            $this->logMethodEnd('Chat session lookup complete' . $this->ctx($context));
        }
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
