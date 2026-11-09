@extends('userinterface::layouts.app')

@section('page-title', 'FAQ Items')
@section('meta-description', 'Manage FAQ items per integration')

@section('content')
<div class="">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fs-6 text-muted">
            Total {{ $faqs->total() }} FAQ Item(s)
        </h5>
        <a href="{{ route('faq.create') }}" class="btn btn-sm btn-outline-primary">
            <i class="fa-regular fa-fw fa-plus"></i>
            <span class="d-none d-md-inline-block ms-1">FAQ Item</span>
        </a>
    </div>

    {{-- Success message --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="d-flex gap-2 mb-3 flex-wrap">
        <select name="integration_id" class="form-select form-select-sm" style="width:auto;">
            <option value="">All Integrations</option>
            @foreach($integrations as $integration)
                <option value="{{ $integration->id }}" @selected(request('integration_id') == $integration->id)>
                    {{ $integration->name }}
                </option>
            @endforeach
        </select>
        <input type="text" name="search" class="form-control form-control-sm" style="width:auto;"
               placeholder="Search question or answer..." value="{{ request('search') }}">
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
        @if(request('integration_id') || request('search'))
            <a href="{{ route('faq.index') }}" class="btn btn-sm btn-outline-danger">Clear</a>
        @endif
    </form>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Integration</th>
                    <th>Question</th>
                    <th>Answer</th>
                    <th>Status</th>
                    <th>Sort</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($faqs as $faq)
                <tr>
                    <td>{{ $faq->id }}</td>
                    <td>{{ optional($faq->integration)->name ?? '—' }}</td>
                    <td>{{ Str::limit($faq->question, 80) }}</td>
                    <td>{{ Str::limit($faq->answer, 80) }}</td>
                    <td>
                        <span class="badge bg-{{ $faq->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($faq->status) }}
                        </span>
                    </td>
                    <td>{{ $faq->sort_order }}</td>
                    <td>
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <a href="{{ route('faq.edit', $faq) }}" class="btn btn-sm btn-outline-dark">
                                <i class="fas fa-fw fa-edit"></i>
                            </a>
                            <form action="{{ route('faq.destroy', $faq) }}" method="POST"
                                  onsubmit="return confirm('Delete this FAQ item?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-fw fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">No FAQ items found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    {{ $faqs->links() }}
</div>
@endsection