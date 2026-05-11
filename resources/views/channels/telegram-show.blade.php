@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([($channel->name ?? 'Channel'), 'Channel']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Show page of Channel'))

@section('content')
@php
    use Illuminate\Support\Str;
    $meta = $channel->metas->pluck('meta_value', 'meta_key');
@endphp

{{-- Channel Header --}}
<div class="d-flex justify-content-between align-items-center mb-2">
    <div class="d-flex align-items-center gap-2">
        <h5 class="fs-6 text-muted mb-0">
            {{ $channel->name }}
            {!! $provider?->getMeta('icon') !!}
        </h5>
        <x-userinterface::status :status="$channel->status" />
    </div>

    <div class="d-flex align-items-center gap-2">
        @if ($channel->status !== 'deleted')
            <a class="btn btn-sm btn-outline-dark"
               href="{{ route('channels.edit', $channel->uid) }}">
                <i class="fas fa-fw fa-edit"></i>
                <span class="d-none d-md-inline-block ms-1">Edit</span>
            </a>

            <form action="{{ route('channels.destroy', $channel->uid) }}"
                  method="POST"
                  onsubmit="return confirm('Are you sure?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-fw fa-trash"></i>
                    <span class="d-none d-md-inline-block ms-1">Delete</span>
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Telegram Details --}}
<div class="mb-3">
    <div class="d-flex align-items-center gap-2 mb-1">
        <div class="text-muted text-nowrap">Bot Username :</div>
        <code>{{ $meta['telegram_bot_username'] ?? '-' }}</code>
    </div>

    <div class="d-flex align-items-start gap-2 mb-1">
        <div class="text-muted text-nowrap">Bot Token :</div>
        <code>
            {{ isset($meta['telegram_bot_token'])
                ? Str::mask($meta['telegram_bot_token'], '*', 0, max(strlen($meta['telegram_bot_token']) - 4, 0))
                : '-' }}
        </code>
    </div>

    <div class="d-flex align-items-start gap-2 mb-1">
        <div class="text-muted text-nowrap">Webhook URL :</div>
        <div class="text-break" id="webhookUrl">
            <code>{{ $webhook_url }}</code>
        </div>
        <i class="fas fa-copy text-muted copy-icon ms-2"
           onclick="copyText('webhookUrl', this)"
           title="Copy URL"></i>
    </div>
</div>

{{-- Webhook Setup --}}
<div class="mb-3">
    <h5 class="text-muted fs-6 mb-2">Webhook Configuration</h5>

    <p class="text-muted small mb-2">
        Enter your public HTTPS URL (e.g. Cloudflare tunnel URL) and click Setup Webhook to register with Telegram.
    </p>

    {{-- Custom URL Input --}}
    <div class="d-flex align-items-center gap-2 mb-2">
        <input
            type="text"
            id="customWebhookUrl"
            class="form-control form-control-sm"
            placeholder="https://your-public-url.com/webhook/telegram/{{ $channel->uid }}"
            value="{{ $webhook_url }}"
        />
    </div>

    {{-- Status message shown by JS --}}
    <div id="webhookStatus"></div>

    <button
        id="setupWebhookBtn"
        class="btn btn-sm btn-outline-primary"
        onclick="setupTelegramWebhook(
            '{{ $meta['telegram_bot_token'] ?? '' }}',
            document.getElementById('customWebhookUrl').value,
            '{{ $meta['telegram_webhook_secret'] ?? '' }}'
        )">
        <i class="fab fa-telegram me-1"></i> Setup Webhook
    </button>
</div>

@push('scripts')
    <script>
        function setupTelegramWebhook(botToken, webhookUrl, secretToken) {
            const btn = document.getElementById('setupWebhookBtn');
            const statusDiv = document.getElementById('webhookStatus');
            const normalizedWebhookUrl = (webhookUrl || '').trim();

            if (!normalizedWebhookUrl) {
                statusDiv.innerHTML = `
                    <div class="alert alert-danger py-2 mt-2">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Please enter a valid webhook URL.
                    </div>`;
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Setting up...';
            statusDiv.innerHTML = '';

            const telegramApiUrl = `https://api.telegram.org/bot${botToken}/setWebhook`;

            const params = new URLSearchParams({
                url: normalizedWebhookUrl,
                secret_token: secretToken,
            });

            fetch(`${telegramApiUrl}?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.ok && data.result === true) {
                        statusDiv.innerHTML = `
                            <div class="alert alert-success py-2 mt-2">
                                <i class="fas fa-check-circle me-2"></i>
                                Webhook registered successfully with Telegram!
                            </div>`;
                    } else {
                        statusDiv.innerHTML = `
                            <div class="alert alert-danger py-2 mt-2">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Failed: ${data.description ?? 'Unknown error'}
                            </div>`;
                    }

                    btn.innerHTML = '<i class="fab fa-telegram me-1"></i> Setup Webhook';
                    btn.disabled = false;
                })
                .catch(error => {
                    statusDiv.innerHTML = `
                        <div class="alert alert-danger py-2 mt-2">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error: ${error.message}. Make sure you are using HTTPS.
                        </div>`;

                    btn.innerHTML = '<i class="fab fa-telegram me-1"></i> Setup Webhook';
                    btn.disabled = false;
                });
        }
    </script>
@endpush

@endsection
