<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Iquesters\SmartMessenger\Models\FaqItem;
use Iquesters\Integration\Models\Integration;

class FaqApiController extends Controller
{
    /**
     * GET /api/faq/{integrationUid}
     * Returns active FAQ items for a given integration.
     * Consumed by chatbot-job FAQ upsert pipeline.
     */
    public function index(string $integrationUid)
    {
        $integration = Integration::where('uid', $integrationUid)->firstOrFail();

        $faqs = FaqItem::where('integration_id', $integration->id)
            ->active()
            ->orderBy('sort_order')
            ->get(['id', 'question', 'answer']);

        return response()->json($faqs);
    }
}