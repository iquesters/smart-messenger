@extends('userinterface::layouts.app')

@section('content')
<div>

    {{-- Webhook Section --}}
    <div class="mb-4">
        <div class="text-uppercase text-muted small mb-3">Webhook Configuration</div>

        {{-- Webhook URL --}}
        <div class="mb-3">
            <div class="small text-muted mb-1">Webhook URL</div>
            <div class="d-flex align-items-center gap-2 bg-light border rounded px-3 py-2">
                <div class="flex-grow-1 text-break" id="webhookUrl">
                    {{ $webhook_url }}
                </div>
                <i class="fa-regular fa-copy text-muted copy-icon"
                   onclick="copyText('webhookUrl', this)"
                   title="Copy"></i>
            </div>
        </div>

        {{-- Verify Token --}}
        <div>
            <div class="small text-muted mb-1">Verify Token</div>
            <div class="d-flex align-items-center gap-2 bg-light border rounded px-3 py-2">
                <div class="flex-grow-1 text-break" id="verifyToken">
                    {{ $webhook_verify_token }}
                </div>
                <i class="fa-regular fa-copy text-muted copy-icon"
                   onclick="copyText('verifyToken', this)"
                   title="Copy"></i>
            </div>
        </div>
    </div>

    {{-- WhatsApp Meta Info --}}
    @php
        $meta = $profile->metas->pluck('meta_value', 'meta_key');
    @endphp

    <div class="mb-4">
        <div class="text-uppercase text-muted small mb-3">WhatsApp Details</div>

        <div class="row mb-2">
            <div class="col-md-4 text-muted">Business ID</div>
            <div class="col-md-8 fw-medium">
                {{ $meta['whatsapp_business_id'] ?? '-' }}
            </div>
        </div>

        <div class="row mb-2">
            <div class="col-md-4 text-muted">Phone Number ID</div>
            <div class="col-md-8 fw-medium">
                {{ $meta['whatsapp_phone_number_id'] ?? '-' }}
            </div>
        </div>

        <div class="row mb-2">
            <div class="col-md-4 text-muted">System User Token</div>
            <div class="col-md-8 fw-medium text-break">
                {{ $meta['system_user_token'] ?? '-' }}
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 text-muted">Phone Number</div>
            <div class="col-md-8 fw-medium">
                {{ ($meta['country_code'] ?? '') . ' ' . ($meta['whatsapp_number'] ?? '') }}
            </div>
        </div>
    </div>

    {{-- Provider --}}
    <div class="mb-4">
        <div class="text-uppercase text-muted small mb-1">Provider</div>
        <div class="fw-semibold">{{ $provider->value }}</div>
    </div>

</div>
@endsection

{{-- Styles --}}
@push('styles')
    <style>
        .copy-icon {
            cursor: pointer;
            transition: color 0.2s ease;
        }
    </style>
@endpush

{{-- Copy Script --}}
@push('scripts')
    <script>
        function copyText(elementId, icon) {
            const text = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(text);

            icon.classList.remove('fa-copy');
            icon.classList.add('fa-check', 'text-success');

            setTimeout(() => {
                icon.classList.remove('fa-check', 'text-success');
                icon.classList.add('fa-copy');
            }, 1200);
        }
    </script>
@endpush