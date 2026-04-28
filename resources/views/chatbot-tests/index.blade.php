@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Chatbot Tests']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Launch and monitor chatbot test runs'))

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Chatbot Test Runner</h5>
            <p class="text-muted mb-0">Use your entity-created test cases and launch controlled runs against a real message pipeline.</p>
        </div>
        <a href="{{ route('messages.index') }}" class="btn btn-sm btn-outline-secondary">Back To Inbox</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <strong>Start Test Run</strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('chatbot-tests.start') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Channel</label>
                            <select name="channel_id" class="form-select" required>
                                <option value="">Select channel</option>
                                @foreach ($channels as $channel)
                                    <option value="{{ $channel->id }}">{{ $channel->name }}{{ (($channel->getMeta('country_code') ?? '') . ($channel->getMeta('whatsapp_number') ?? '')) ? ' (' . (($channel->getMeta('country_code') ?? '') . ($channel->getMeta('whatsapp_number') ?? '')) . ')' : '' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Interval Minutes</label>
                            <input type="number" name="interval_minutes" value="5" min="1" class="form-control">
                            <small class="text-muted">One pending case is dispatched per interval.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cases</label>
                            <div class="border rounded p-2" style="max-height: 260px; overflow:auto;">
                                @forelse ($cases as $case)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="case_uids[]" value="{{ $case->uid }}" id="case-{{ $case->uid }}">
                                        <label class="form-check-label" for="case-{{ $case->uid }}">
                                            <span class="fw-semibold">{{ $case->name }}</span>
                                            <br>
                                            <small class="text-muted">{{ \Illuminate\Support\Str::limit($case->question, 90) }}</small>
                                        </label>
                                    </div>
                                @empty
                                    <p class="text-muted mb-0">No active chatbot test cases found.</p>
                                @endforelse
                            </div>
                            <small class="text-muted">Leave all unchecked to run every active case.</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Start Run</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Active Test Cases</strong>
                    <span class="text-muted small">{{ $cases->count() }} available</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Question</th>
                                <th>Expectation</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cases as $case)
                                <tr>
                                    <td class="fw-semibold">{{ $case->name }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($case->question, 120) }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($case->expected_contains ?: $case->expected_answer ?: 'No assertion set', 100) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">No active chatbot test cases found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Recent Runs</strong>
                    <span class="text-muted small">{{ $runs->count() }} shown</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Run UID</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Next Dispatch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($runs as $run)
                                <tr>
                                    <td>
                                        <a href="{{ route('chatbot-tests.show', $run->run_uid) }}" class="text-decoration-none fw-semibold">{{ $run->run_uid }}</a>
                                        <br>
                                        <small class="text-muted">Channel ID: {{ $run->channel_id }}</small>
                                    </td>
                                    <td>
                                        <x-userinterface::status :status="$run->status" />
                                    </td>
                                    <td>
                                        {{ $run->processed_cases ?? 0 }}/{{ $run->total_cases ?? 0 }}
                                        <br>
                                        <small class="text-muted">Pass: {{ $run->passed_cases ?? 0 }} | Fail: {{ $run->failed_cases ?? 0 }}</small>
                                    </td>
                                    <td>{{ optional($run->next_dispatch_at)->format('d M Y H:i') ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No runs have been started yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection