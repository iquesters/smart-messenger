<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosticsController extends Controller
{
    public function show(Request $request, string $integrationId, string $messageId): JsonResponse
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get(
                    sprintf(
                        'https://api-util.iquesters.com/v1/diagnostics/%s/message/%s',
                        rawurlencode($integrationId),
                        rawurlencode($messageId)
                    )
                );

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch diagnostics from upstream API',
                    'status' => $response->status(),
                ], $response->status());
            }

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error('Diagnostics proxy failed', [
                'integration_id' => $integrationId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Diagnostics proxy request failed',
            ], 500);
        }
    }
}

