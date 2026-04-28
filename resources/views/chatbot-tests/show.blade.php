@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Chatbot Test Run']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('View chatbot test run progress and results'))

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Chatbot Test Run</h5>
            <p class="text-muted mb-0">{{ $run->run_uid }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('chatbot-tests.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
            @if (!in_array($run->status, ['completed', 'cancelled', 'failed'], true))
                <form method="POST" action="{{ route('chatbot-tests.cancel', $run->run_uid) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel Run</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Status</div><div class="fw-semibold"><x-userinterface::status :status="$run->status" /></div></div></div></div>
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Total</div><div class="fw-semibold">{{ $run->total_cases ?? 0 }}</div></div></div></div>
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Processed</div><div class="fw-semibold">{{ $run->processed_cases ?? 0 }}</div></div></div></div>
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Passed</div><div class="fw-semibold text-success">{{ $run->passed_cases ?? 0 }}</div></div></div></div>
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Failed</div><div class="fw-semibold text-danger">{{ $run->failed_cases ?? 0 }}</div></div></div></div>
        <div class="col-md-2 col-6"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Next Dispatch</div><div class="fw-semibold small">{{ optional($run->next_dispatch_at)->format('d M Y H:i') ?? '-' }}</div></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Run Items</strong>
            <span class="text-muted small">One row per test case execution</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-top">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Case</th>
                        <th>Question</th>
                        <th>Expected</th>
                        <th>Actual</th>
                        <th>Status</th>
                        <th>Inbound</th>
                        <th>Outbound</th>
                        <th>Processed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php
                            $itemMeta = $item->meta ?? [];
                            $expectedContains = $item->expected_contains ?? ($itemMeta['expected_contains'] ?? null);
                            $expectedAnswer = $item->expected_answer ?? ($itemMeta['expected_answer'] ?? null);
                            $linkedReplies = $outboundRepliesByItemUid->get($item->uid, collect());
                            $actualDisplay = $item->actual_answer ?? $linkedReplies->map(function ($message) {
                                if ($message->message_type === 'text') {
                                    return trim((string) $message->content);
                                }

                                $caption = trim((string) ($message->caption() ?? ''));
                                return $caption !== '' ? $caption : '[' . $message->message_type . ' reply]';
                            })->filter()->implode("\n\n");
                        @endphp
                        <tr>
                            <td>{{ $item->sequence_no ?? '-' }}</td>
                            <td>{{ $casesByUid[$item->chatbot_test_case_uid] ?? $item->chatbot_test_case_uid }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($item->question, 120) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($expectedContains ?: $expectedAnswer ?: '-', 100) }}</td>
                            <td>
                                @if ($actualDisplay)
                                    <div style="white-space: pre-line;">{{ $actualDisplay }}</div>
                                @elseif ($item->status === 'dispatched')
                                    <span class="text-muted">Waiting for chatbot reply...</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td><x-userinterface::status :status="$item->status" /></td>
                            <td>{{ $item->inbound_message_id ?: '-' }}</td>
                            <td>
                                @if ($linkedReplies->isNotEmpty())
                                    @foreach ($linkedReplies as $reply)
                                        <div>#{{ $reply->id }}</div>
                                    @endforeach
                                @else
                                    {{ $item->outbound_message_id ?: '-' }}
                                @endif
                            </td>
                            <td>{{ optional($item->processed_at)->format('d M Y H:i') ?? '-' }}</td>
                        </tr>
                        @if ($linkedReplies->count() > 1)
                            <tr>
                                <td></td>
                                <td colspan="8">
                                    <small class="text-muted">{{ $linkedReplies->count() }} outbound replies linked to this test item.</small>
                                </td>
                            </tr>
                        @endif
                        @if(!empty($item->error_message))
                            <tr>
                                <td></td>
                                <td colspan="8"><small class="text-danger">{{ $item->error_message }}</small></td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No run items found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection