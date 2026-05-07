@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([($isEdit ? 'Edit' : 'Create'), 'Channel']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Create/Edit of Channel'))

@section('content')
<div>

    <h5 class="mb-2 fs-6 text-muted">
        {{ $isEdit ? 'Edit Channel' : 'Create Channel' }}
    </h5>

    {{-- Back Button (Step 2 only) --}}
    @if($step == 2)
        <div class="mb-3">
            <a href="{{ $isEdit ? route('channels.edit', $channel->uid) : route('channels.create') }}"
               class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-fw fa-arrow-left"></i> Back
            </a>
        </div>
    @endif

    {{-- Progress Steps --}}
    <div class="mb-4">
        <div class="d-flex align-items-center">
            <div class="d-flex align-items-center {{ $step == 1 ? 'text-primary' : 'text-success' }}">
                <div class="rounded-circle border {{ $step == 1 ? 'border-primary' : 'border-success' }} d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: 500;">
                    @if($step == 2) ✓ @else 1 @endif
                </div>
                <span class="ms-2 small">Basic Info</span>
            </div>
            <div class="flex-grow-1 mx-3" style="height: 2px; background: {{ $step == 2 ? '#198754' : '#dee2e6' }};"></div>
            <div class="d-flex align-items-center {{ $step == 2 ? 'text-primary' : 'text-muted' }}">
                <div class="rounded-circle border {{ $step == 2 ? 'border-primary' : 'border-secondary' }} d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: 500;">
                    2
                </div>
                <span class="ms-2 small">Telegram Details</span>
            </div>
        </div>
    </div>

    <div>
        <form
            action="{{ $step == 1 ? ($isEdit ? route('channels.update-step1', $channel->uid) : route('channels.store-step1')) : ($isEdit ? route('channels.update', $channel->uid) : route('channels.store')) }}"
            method="POST">

            @csrf
            @if($isEdit && $step == 2)
                @method('PUT')
            @endif

            {{-- STEP 1 --}}
            @if($step == 1)
                @include('smartmessenger::components.channel-step1-form')

            {{-- STEP 2: Telegram Fields --}}
            @else
                <div class="row g-3 mb-3">

                    {{-- BOT TOKEN --}}
                    <div class="col-12 col-md-6">
                        <label class="form-label">Bot Token <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[telegram_bot_token]"
                            class="form-control @error('meta.telegram_bot_token') is-invalid @enderror"
                            placeholder="Paste Bot Token from BotFather"
                            value="{{ old('meta.telegram_bot_token', $channel?->getMeta('telegram_bot_token') ?? '') }}"
                            required>
                        @error('meta.telegram_bot_token')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- BOT USERNAME --}}
                    <div class="col-12 col-md-6">
                        <label class="form-label">Bot Username <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[telegram_bot_username]"
                            class="form-control @error('meta.telegram_bot_username') is-invalid @enderror"
                            placeholder="e.g. IquesterTele_Bot"
                            value="{{ old('meta.telegram_bot_username', $channel?->getMeta('telegram_bot_username') ?? '') }}"
                            required>
                        @error('meta.telegram_bot_username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                </div>

                {{-- WEBHOOK SECRET --}}
                <div class="mb-3">
                    <label class="form-label">Webhook Secret <span class="text-danger">*</span></label>
                    <input type="text"
                        name="meta[telegram_webhook_secret]"
                        class="form-control @error('meta.telegram_webhook_secret') is-invalid @enderror"
                        placeholder="Paste your generated webhook secret"
                        value="{{ old('meta.telegram_webhook_secret', $channel?->getMeta('telegram_webhook_secret') ?? '') }}"
                        required>
                    @error('meta.telegram_webhook_secret')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">Generate using: <code>echo bin2hex(random_bytes(32));</code> in tinker</div>
                </div>
            @endif

            {{-- Buttons --}}
            <div class="mt-4 d-flex align-items-center justify-content-end gap-2">
                <a href="{{ route('channels.index') }}" class="btn btn-sm btn-outline-dark">
                    Cancel
                </a>
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    @if($step == 1) Next
                    @else {{ $isEdit ? 'Update Channel' : 'Create Channel' }}
                    @endif
                </button>
            </div>

        </form>
    </div>

</div>
@endsection