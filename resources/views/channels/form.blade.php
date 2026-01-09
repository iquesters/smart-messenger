@extends('userinterface::layouts.app')

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
                    @if($step == 2)
                        ✓
                    @else
                        1
                    @endif
                </div>
                <span class="ms-2 small">Basic Info</span>
            </div>
            <div class="flex-grow-1 mx-3" style="height: 2px; background: {{ $step == 2 ? '#198754' : '#dee2e6' }};"></div>
            <div class="d-flex align-items-center {{ $step == 2 ? 'text-primary' : 'text-muted' }}">
                <div class="rounded-circle border {{ $step == 2 ? 'border-primary' : 'border-secondary' }} d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: 500;">
                    2
                </div>
                <span class="ms-2 small">WhatsApp Details</span>
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

            {{-- STEP 1: Basic Information --}}
            @if($step == 1)
                <div class="row g-3 mb-3">

                    {{-- PROVIDER --}}
                    <div class="col-12 col-md-4">
                        <label class="form-label">Provider <span class="text-danger">*</span></label>

                        @if(!empty($provider))
                            <input type="text"
                                class="form-control"
                                value="{{ $provider->name }}"
                                disabled>

                            <input type="hidden" name="channel_provider_id" value="{{ $provider->id }}">
                        @else
                            <select name="channel_provider_id"
                                class="form-select @error('channel_provider_id') is-invalid @enderror"
                                required>

                                <option value="">-- Select Provider --</option>

                                @foreach($providers as $prov)
                                    <option value="{{ $prov->id }}"
                                        {{ old('channel_provider_id', $sessionData['channel_provider_id'] ?? '') == $prov->id ? 'selected' : '' }}>
                                        {{ $prov->name }}
                                    </option>
                                @endforeach
                            </select>

                            @error('channel_provider_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>

                    {{-- CHANNEL NAME --}}
                    <div class="col-12 col-md-4">
                        <label class="form-label">Channel Name <span class="text-danger">*</span></label>
                        <input type="text"
                            name="name"
                            class="form-control @error('name') is-invalid @enderror"
                            placeholder="Channel name"
                            value="{{ old('name', $sessionData['name'] ?? $channel->name ?? '') }}"
                            required>

                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- ORGANISATION --}}
                    @if($organisations->count() > 0)
                        <div class="col-12 col-md-4">
                            <label class="form-label">Organisation</label>

                            <select name="organisation_id"
                                class="form-select @error('organisation_id') is-invalid @enderror">

                                <option value="">-- Select Organisation --</option>

                                @foreach($organisations as $org)
                                    <option value="{{ $org->id }}"
                                        {{ old('organisation_id', $sessionData['organisation_id'] ?? $assignedOrganisationId ?? '') == $org->id ? 'selected' : '' }}>
                                        {{ $org->name }}
                                    </option>
                                @endforeach
                            </select>

                            @error('organisation_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif

                </div>

                {{-- STATUS (EDIT ONLY) --}}
                @if($isEdit)
                <div class="mb-3">
                    <label class="form-label">Status</label>

                    <select name="status" 
                        class="form-select @error('status') is-invalid @enderror">
                        <option value="active"   {{ old('status', $sessionData['status'] ?? $channel->status) == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $sessionData['status'] ?? $channel->status) == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>

                    @error('status')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                @endif

            {{-- STEP 2: WhatsApp Details --}}
            @else
                <div class="row g-3 mb-3">

                    {{-- COUNTRY CODE --}}
                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="form-label">Country Code <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[country_code]"
                            class="form-control @error('meta.country_code') is-invalid @enderror"
                            placeholder="e.g. +91"
                            value="{{ old('meta.country_code', $channel?->getMeta('country_code') ?? '') }}"
                            required>
                        @error('meta.country_code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- WHATSAPP NUMBER --}}
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label">WhatsApp Number <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[whatsapp_number]"
                            class="form-control @error('meta.whatsapp_number') is-invalid @enderror"
                            placeholder="Enter WhatsApp Number"
                            value="{{ old('meta.whatsapp_number', $channel?->getMeta('whatsapp_number') ?? '') }}"
                            required>
                        @error('meta.whatsapp_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- BUSINESS ACCOUNT ID --}}
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label">Business Account ID <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[whatsapp_business_id]"
                            class="form-control @error('meta.whatsapp_business_id') is-invalid @enderror"
                            placeholder="Business Account ID"
                            value="{{ old('meta.whatsapp_business_id', $channel?->getMeta('whatsapp_business_id') ?? '') }}"
                            required>
                        @error('meta.whatsapp_business_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- PHONE NUMBER ID --}}
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Phone Number ID <span class="text-danger">*</span></label>
                        <input type="text"
                            name="meta[whatsapp_phone_number_id]"
                            class="form-control @error('meta.whatsapp_phone_number_id') is-invalid @enderror"
                            placeholder="Phone Number ID"
                            value="{{ old('meta.whatsapp_phone_number_id', $channel?->getMeta('whatsapp_phone_number_id') ?? '') }}"
                            required>
                        @error('meta.whatsapp_phone_number_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                </div>

                {{-- SYSTEM USER TOKEN --}}
                <div class="mb-3">
                    <label class="form-label">System User Token <span class="text-danger">*</span></label>
                    <textarea 
                        name="meta[system_user_token]" 
                        class="form-control @error('meta.system_user_token') is-invalid @enderror" 
                        rows="3"
                        placeholder="Paste System User Token"
                        required>{{ old('meta.system_user_token', $channel?->getMeta('system_user_token') ?? '') }}</textarea>
                    @error('meta.system_user_token')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            {{-- Buttons --}}
            <div class="mt-4 d-flex align-items-center justify-content-end gap-2">
                <a href="{{ route('channels.index') }}" class="btn btn-sm btn-outline-dark">
                    Cancel
                </a>

                <button type="submit" class="btn btn-sm btn-outline-primary">
                    @if($step == 1)
                        Next
                    @else
                        {{ $isEdit ? 'Update Channel' : 'Create Channel' }}
                    @endif
                </button>
            </div>

        </form>
    </div>

</div>
@endsection