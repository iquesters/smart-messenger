<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Webhook;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class BaseWHController extends Controller
{
    /**
     * Determines if webhook should be processed asynchronously
     */
    protected ?bool $async = true;

    /**
     * Get the job class for processing webhook
     */
    abstract protected function getJobClass(): string;

    /**
     * Handle webhook verification (GET request)
     */
    abstract protected function handleVerification(Request $request, string $channelUid): mixed;

    /**
     * Get provider name from controller class name
     */
    protected function getProviderName(): string
    {
        // Extract provider from class name: WhatsAppWHController -> whatsapp
        $className = class_basename(static::class);
        $providerName = str_replace(['WHController', 'Controller'], '', $className);
        
        return strtolower($providerName);
    }

    /**
     * Main webhook handler
     */
    public function handle(Request $request, string $channelUid)
    {
        try {
            // Handle GET verification
            if ($request->isMethod('get')) {
                return $this->handleVerification($request, $channelUid);
            }

            // If async, dispatch and return 200 immediately
            if ($this->async) {
                $jobClass = $this->getJobClass();

                $jobClass::dispatch(
                    $request->all(),
                    $channelUid
                );

                Log::info('Webhook queued for async processing', [
                    'provider' => $this->getProviderName(),
                    'channel_uid' => $channelUid,
                    'job_class' => $jobClass
                ]);

                return response()->json(['status' => 'ok'], 200);
            }

            // Sync processing
            $jobClass = $this->getJobClass();

            $job = new $jobClass(
                $request->all(),
                $channelUid
            );

            $job->handle();

            return response()->json(['status' => 'ok'], 200);

        } catch (\Throwable $e) {
            Log::error('Webhook Fatal Error', [
                'provider' => $this->getProviderName(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'channel_uid' => $channelUid
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }
}