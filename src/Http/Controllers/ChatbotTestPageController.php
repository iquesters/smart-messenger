<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Iquesters\SmartMessenger\Jobs\MessageJobs\RunChatbotTestBatchJob;
use Iquesters\SmartMessenger\Models\Channel;
use Iquesters\SmartMessenger\Models\Message;
use Iquesters\SmartMessenger\Services\ChatbotTestEntityService;

class ChatbotTestPageController extends Controller
{
    public function __construct(protected ChatbotTestEntityService $entityService)
    {
    }

    public function index()
    {
        $this->entityService->assertRunnerSchemaReady();

        $cases = $this->entityService->getActiveCases();
        $runs = $this->entityService->attachMeta(
            ChatbotTestEntityService::RUNS_TABLE,
            DB::table(ChatbotTestEntityService::RUNS_TABLE)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
        );
        $channels = $this->accessibleChannels();

        return view('smartmessenger::chatbot-tests.index', compact('cases', 'runs', 'channels'));
    }

    public function start(Request $request): RedirectResponse
    {
        $this->entityService->assertRunnerSchemaReady();

        $data = $request->validate([
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
            'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'case_uids' => ['nullable', 'array'],
            'case_uids.*' => ['string'],
        ]);

        $cases = $this->entityService->getActiveCases($data['case_uids'] ?? []);
        if ($cases->isEmpty()) {
            return redirect()->route('chatbot-tests.index')->with('error', 'No active chatbot test cases matched the request.');
        }

        $run = $this->entityService->createRun(
            (int) $data['channel_id'],
            (int) (Auth::id() ?? 0),
            (int) ($data['interval_minutes'] ?? 5),
            $cases
        );

        RunChatbotTestBatchJob::dispatch($run->run_uid);

        return redirect()->route('chatbot-tests.show', $run->run_uid)
            ->with('success', 'Chatbot test run started successfully.');
    }

    public function show(string $runUid)
    {
        $this->entityService->assertRunnerSchemaReady();

        $run = $this->entityService->getRunByRunUid($runUid);
        abort_unless($run, 404);

        $items = $this->entityService->getRunItems($runUid);
        $casesByUid = DB::table(ChatbotTestEntityService::CASES_TABLE)
            ->whereIn('uid', $items->pluck('chatbot_test_case_uid')->filter()->all())
            ->pluck('name', 'uid');

        $itemUids = $items->pluck('uid')->filter()->all();
        $outboundRepliesByItemUid = collect();

        if (!empty($itemUids)) {
            $messages = Message::query()
                ->with('metas')
                ->whereHas('metas', function ($query) use ($itemUids) {
                    $query->where('meta_key', 'chatbot_test_run_item_uid')
                        ->whereIn('meta_value', $itemUids);
                })
                ->orderBy('id')
                ->get();

            $outboundRepliesByItemUid = $messages->groupBy(function (Message $message) {
                return $message->getMeta('chatbot_test_run_item_uid');
            })->map(function ($group) {
                return $group->values();
            });
        }

        return view('smartmessenger::chatbot-tests.show', compact('run', 'items', 'casesByUid', 'outboundRepliesByItemUid'));
    }

    public function cancel(string $runUid): RedirectResponse
    {
        $this->entityService->cancelRun($runUid, (int) (Auth::id() ?? 0));

        return redirect()->route('chatbot-tests.show', $runUid)
            ->with('success', 'Chatbot test run cancelled successfully.');
    }

    protected function accessibleChannels()
    {
        $user = Auth::user();
        if (!$user) {
            return collect();
        }

        $organisationIds = collect();
        if (method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        return Channel::query()
            ->where('status', 'active')
            ->where(function ($query) use ($user, $organisationIds) {
                $query->where('created_by', $user->id);

                if ($organisationIds->isNotEmpty() && method_exists(Channel::class, 'organisations')) {
                    $query->orWhereHas('organisations', function ($innerQuery) use ($organisationIds) {
                        $innerQuery->whereIn('organisations.id', $organisationIds);
                    });
                }
            })
            ->with(['metas', 'provider'])
            ->orderBy('name')
            ->get();
    }
}