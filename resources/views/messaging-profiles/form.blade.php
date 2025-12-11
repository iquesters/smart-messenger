@extends('userinterface::layouts.app')

@section('content')
<div>

    <h5 class="mb-2 fs-6 text-muted">
        {{ $isEdit ? 'Edit Messaging Profile' : 'Create Messaging Profile' }}
    </h5>

    <div>
        <form 
            action="{{ $isEdit ? route('profiles.update', $profile->uid) : route('profiles.store') }}" 
            method="POST">

            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            {{-- PROVIDER NAME (READ ONLY) --}}
            <div class="mb-3">
                <label class="form-label">Provider</label>
                <input type="text" 
                    class="form-control" 
                    value="{{ $provider->value }}" 
                    disabled>

                <input type="hidden" name="provider_id" value="{{ $provider->id }}">
            </div>

            {{-- PROFILE NAME --}}
            <div class="mb-3">
                <label class="form-label">Profile Name</label>
                <input type="text" 
                    name="name" 
                    class="form-control @error('name') is-invalid @enderror"
                    placeholder="profile name"
                    value="{{ old('name', $profile->name ?? '') }}"
                    required>

                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- ORGANISATION --}}
            @if($organisations->count() > 0)
            <div class="mb-3">
                <label class="form-label">Organisation</label>

                <select name="organisation_id" 
                    class="form-select @error('organisation_id') is-invalid @enderror">

                    <option value="">-- Select Organisation --</option>

                    @foreach($organisations as $org)
                        <option value="{{ $org->id }}"
                            {{ old('organisation_id', $assignedOrganisationId ?? '') == $org->id ? 'selected' : '' }}>
                            {{ $org->name }}
                        </option>
                    @endforeach
                </select>

                @error('organisation_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            @endif

            {{-- ---------------------------- --}}
            {{-- WHATSAPP META FIELDS --}}
            {{-- ---------------------------- --}}

            {{-- WHATSAPP BUSINESS ID --}}
            <div class="mb-3">
                <label class="form-label">WhatsApp Business ID</label>
                <input type="text" 
                    name="meta[whatsapp_business_id]"
                    class="form-control"
                    placeholder="WhatsApp Business ID"
                    value="{{ old('meta.whatsapp_business_id', $profile?->getMeta('whatsapp_business_id') ?? '') }}">
            </div>

            {{-- WHATSAPP PHONE NUMBER ID --}}
            <div class="mb-3">
                <label class="form-label">WhatsApp Phone Number ID</label>
                <input type="text" 
                    name="meta[whatsapp_phone_number_id]"
                    class="form-control"
                    placeholder="WhatsApp Phone Number ID"
                    value="{{ old('meta.whatsapp_phone_number_id', $profile?->getMeta('whatsapp_phone_number_id') ?? '') }}">
            </div>

            {{-- SYSTEM USER TOKEN --}}
            <div class="mb-3">
                <label class="form-label">System User Token</label>
                <textarea 
                    name="meta[system_user_token]" 
                    class="form-control" 
                    rows="3"
                    placeholder="Paste System User Token">{{ old('meta.system_user_token', $profile?->getMeta('system_user_token') ?? '') }}</textarea>
            </div>

            {{-- STATUS (EDIT ONLY) --}}
            @if($isEdit)
            <div class="mb-3">
                <label class="form-label">Status</label>

                <select name="status" 
                    class="form-select @error('status') is-invalid @enderror">
                    <option value="active"   {{ old('status', $profile->status) == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $profile->status) == 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>

                @error('status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            @endif

            <div class="mt-4 d-flex align-items-center justify-content-end gap-2">
                <a href="{{ route('profiles.index') }}" class="btn btn-sm btn-outline-dark">
                    Cancel
                </a>

                <button type="submit" class="btn btn-sm btn-outline-primary">
                    {{ $isEdit ? 'Update Profile' : 'Create Profile' }}
                </button>
            </div>

        </form>
    </div>

</div>
@endsection