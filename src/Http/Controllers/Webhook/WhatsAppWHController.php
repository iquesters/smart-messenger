<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Iquesters\SmartMessenger\Constants\Constants;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Jobs\WhatsAppWHJob;

class WhatsAppWHController extends BaseWHController
{
    /**
     * Enable async processing
     */
    protected ?bool $async = true;

    /**
     * Get the job class for WhatsApp webhook processing
     */
    protected function getJobClass(): string
    {
        return WhatsAppWHJob::class;
    }

    /**
     * Handle webhook verification (GET request)
     */
    protected function handleVerification(Request $request, string $channelUid): mixed
    {
        if ($request->input('hub_mode') !== 'subscribe') {
            return response('Invalid hub mode', 403);
        }

        $verifyToken = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        // Find channel
        $channel = Channel::where('uid', $channelUid)
            ->where('status', Constants::ACTIVE)
            ->with(['metas', 'provider'])
            ->first();

        if (!$channel) {
            Log::warning('Channel not found or inactive', ['channel_uid' => $channelUid]);
            return response('Invalid channel', 403);
        }

        // Verify token
        $meta = $channel->metas()
            ->where('meta_key', 'webhook_verify_token')
            ->where('meta_value', $verifyToken)
            ->first();

        if (!$meta) {
            Log::warning('Invalid verification token for channel', ['channel_uid' => $channelUid]);
            return response('Invalid verification token', 403);
        }

        Log::info('Webhook verified', ['channel_uid' => $channelUid]);
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }
}