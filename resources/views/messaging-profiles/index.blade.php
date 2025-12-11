@extends('userinterface::layouts.app')

@section('content')
<div class="">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fs-6 text-muted">Total {{ $profiles->count() }} Messaging Profiles</h5>

        <a href="#provider" class="btn btn-sm btn-outline-primary">
            <i class="fa-regular fa-fw fa-plus"></i>
            <span class="d-none d-md-inline-block ms-1">Profile</span>
        </a>
    </div>


    <div class="table-responsive">
        <table id="profiles-table" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                @foreach($profiles as $profile)
                <tr>
                    <td>
                        {{-- <a href="{{ route('profiles.show', $profile->uid) }}" class="text-decoration-none"> --}}
                            {{ $profile->name }}
                        {{-- </a> --}}
                        <br>
                        <small class="text-muted">{{ $profile->uid }}</small>
                    </td>

                    <td>
                        <span class="badge badge-{{ strtolower($profile->status) }}">
                            {{ ucfirst($profile->status) }}
                        </span>
                    </td>

                    <td>{{ $profile->created_at->format('d M Y') }}</td>

                    <td>
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <a class="btn btn-sm btn-outline-dark" href="{{ route('profiles.edit', $profile->uid) }}">
                                <i class="fas fa-fw fa-edit"></i>
                            </a>
                            
                            <form action="{{ route('profiles.destroy', $profile->uid) }}" 
                                method="POST" 
                                onsubmit="return confirm('Are you sure?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-fw fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>

        </table>
    </div>

    <hr class="my-4">

    <h5 class="fs-6 text-muted mb-3">Messaging Providers</h5>

    <div class="row" id="provider">
        @forelse($providers as $provider)
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm border">
                    <div class="card-body">

                        <h6 class="fw-bold">{{ $provider->value }}</h6>

                        <a href="{{ route('profiles.create') }}?provider_id={{ $provider->id }}"
                            class="btn btn-sm btn-primary mt-2">
                            <i class="fa fa-plus me-1"></i> Create Profile
                        </a>

                    </div>
                </div>
            </div>
        @empty
            <p class="text-muted">No provider found.</p>
        @endforelse
    </div>

</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#profiles-table').DataTable({
            responsive: true,
            order: [[2, 'desc']]
        });
    });
</script>
@endpush