@extends('userinterface::layouts.app')

@section('page-title', isset($faq) ? 'Edit FAQ Item' : 'Create FAQ Item')

@section('content')
<div class="" style="max-width: 700px;">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fs-6 text-muted mb-0">
            {{ isset($faq) ? 'Edit FAQ Item' : 'Create FAQ Item' }}
        </h5>
        <a href="{{ route('faq.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-fw fa-arrow-left"></i>
            <span class="ms-1">Back</span>
        </a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ isset($faq) ? route('faq.update', $faq) : route('faq.store') }}">
        @csrf
        @if(isset($faq)) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Integration <span class="text-danger">*</span></label>
            <select name="integration_id" class="form-select" required>
                <option value="">— Select Integration —</option>
                @foreach($integrations as $integration)
                    <option value="{{ $integration->id }}"
                        @selected(old('integration_id', $faq->integration_id ?? '') == $integration->id)>
                        {{ $integration->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Question <span class="text-danger">*</span></label>
            <textarea name="question" class="form-control" rows="3" required
                      placeholder="e.g. What are your delivery timings?">{{ old('question', $faq->question ?? '') }}</textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Answer <span class="text-danger">*</span></label>
            <textarea name="answer" class="form-control" rows="5" required
                      placeholder="e.g. We deliver between 9am and 6pm Monday to Friday.">{{ old('answer', $faq->answer ?? '') }}</textarea>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active"
                        @selected(old('status', $faq->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive"
                        @selected(old('status', $faq->status ?? '') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control" min="0"
                       value="{{ old('sort_order', $faq->sort_order ?? 0) }}">
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-fw fa-save"></i>
                {{ isset($faq) ? 'Update' : 'Create' }}
            </button>
            <a href="{{ route('faq.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection