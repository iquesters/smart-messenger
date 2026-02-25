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

{{-- WhatsApp Details --}}
<div class="mb-3">

    <div class="d-flex align-items-center gap-2 mb-1">
        <div class="text-muted text-nowrap">Business ID :</div>
        <code>{{ $meta['whatsapp_business_id'] ?? '-' }}</code>
    </div>

    <div class="d-flex align-items-center gap-2 mb-1">
        <div class="text-muted text-nowrap">Phone Number ID :</div>
        <code>{{ $meta['whatsapp_phone_number_id'] ?? '-' }}</code>
    </div>

    <div class="d-flex align-items-start gap-2 mb-1">
        <div class="text-muted text-nowrap">System User Token :</div>
        <code>
            {{ isset($meta['system_user_token'])
                ? Str::mask($meta['system_user_token'], '*', 0, max(strlen($meta['system_user_token']) - 4, 0))
                : '-' }}
        </code>
    </div>

    <div class="d-flex align-items-center gap-2">
        <div class="text-muted text-nowrap">Phone Number :</div>
        <code>
            {{ ($meta['country_code'] ?? '') . ' ' . ($meta['whatsapp_number'] ?? '') }}
        </code>
    </div>
</div>

{{-- Webhook Configuration --}}
<div class="mb-3">
    <h5 class="text-muted fs-6 mb-2">
        Webhook Configuration
    </h5>

    {{-- Webhook URL --}}
    <div class="d-flex align-items-start gap-2 mb-2">
        <div class="text-muted text-nowrap">Webhook URL :</div>

        <div class="text-break" id="webhookUrl">
            <code>{{ $webhook_url }}</code>
        </div>

        <i class="fas fa-copy text-muted copy-icon ms-2"
           onclick="copyText('webhookUrl', this)"
           title="Copy URL"></i>
    </div>

    {{-- Verify Token --}}
    <div class="d-flex align-items-start gap-2">
        <div class="text-muted text-nowrap">Verify Token :</div>

        <div class="text-break" id="verifyToken">
            <code>{{ $webhook_verify_token ?? '-' }}</code>
        </div>

        <i class="fas fa-copy text-muted copy-icon ms-2"
           onclick="copyText('verifyToken', this)"
           title="Copy Token"></i>
    </div>
</div>

{{-- Placeholder for future provider integrations --}}
<div class="text-muted">
    Integration section
</div>
@endsection