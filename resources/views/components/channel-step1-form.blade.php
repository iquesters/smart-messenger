{{-- Step 1: Basic Information --}}
{{-- This component is shared across all provider forms --}}

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