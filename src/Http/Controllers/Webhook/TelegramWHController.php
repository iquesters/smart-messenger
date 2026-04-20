<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Jobs\TelegramWHJob;
use Iquesters\SmartMessenger\Models\Channel;

class TelegramWHController extends BaseWHController
{
    /**
     * Run webhook async (same as WhatsApp)
     */
    protected ?bool $async = true;

    /**
     * Job to dispatch
     */
    protected function getJobClass(): string
    {
        return TelegramWHJob::class;
    }

    /**
     * Telegram does NOT have verification like WhatsApp
     */
    protected function handleVerification(Request $request, string $channelUid): mixed
    {
        return response()->json([
            'status'  => 'ok',
            'message' => 'Telegram webhook active',
            'channel' => $channelUid
        ], 200);
    }

    /**
     * Validate Telegram Secret Token
     * This MUST be called before dispatching job
     */
    protected function validateSecretToken(Request $request, string $channelUid): bool
    {
        try {
            $channel = Channel::where('uid', $channelUid)
                ->where('status', 'active')
                ->first();

            if (!$channel) {
                Log::warning('Telegram WH: Channel not found', [
                    'channel_uid' => $channelUid
                ]);
                return false;
            }

            $storedSecret   = $channel->getMeta('telegram_webhook_secret');
            $incomingSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

            // If no secret is configured → allow (optional behavior)
            if (!$storedSecret) {
                Log::info('Telegram WH: No secret configured, skipping validation');
                return true;
            }

            if (!$incomingSecret) {
                Log::warning('Telegram WH: Missing secret token header');
                return false;
            }

            return hash_equals((string) $storedSecret, (string) $incomingSecret);

        } catch (\Throwable $e) {
            Log::error('Telegram WH: Secret validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Override handle to inject secret validation
     */
    public function handle(Request $request, string $channelUid)
    {
        // Validate secret BEFORE dispatching job
        if (!$this->validateSecretToken($request, $channelUid)) {
            return response()->json([
                'status' => 'unauthorized'
            ], 403);
        }

        // Continue normal BaseWHController flow
        return parent::handle($request, $channelUid);
    }
}