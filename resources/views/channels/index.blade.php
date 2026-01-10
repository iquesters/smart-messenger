@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Channel']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('List of Channel'))

@section('content')
<div class="">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fs-6 text-muted">
            Total {{ $channels->count() }} Channel(s)
        </h5>

        <a href="{{ route('channels.create') }}" class="btn btn-sm btn-outline-primary">
            <i class="fa-regular fa-fw fa-plus"></i>
            <span class="d-none d-md-inline-block ms-1">Channel</span>
        </a>
    </div>

    <div class="table-responsive">
        <table id="channels-table" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Organisation</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                @foreach($channels as $channel)
                <tr>
                    <td>
                        <a href="{{ route('channels.show', $channel->uid) }}" class="text-decoration-none">
                            {{ $channel->name }}
                            {!! $channel->provider?->getMeta('icon') !!}
                        </a>
                        <br>
                        <small class="text-muted">{{ $channel->getMeta('country_code') ?? '' }} {{ $channel->getMeta('whatsapp_number') ?? '' }}</small>
                    </td>

                    <td>
                        <span class="badge badge-{{ strtolower($channel->status) }}">
                            {{ ucfirst($channel->status) }}
                        </span>
                    </td>

                    <td>
                        {{
                            optional($channel->creator)->name ?? '-'
                        }}
                        <br>
                        <small>
                            {{ $channel->created_at->format('d M Y') }}
                        </small>
                    </td>
                    <td>
                        {{
                            method_exists($channel, 'organisations')
                                ? optional($channel->organisations->first())->name ?? '-'
                                : '-'
                        }}
                        </td>
                    <td>
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <a
                                class="btn btn-sm btn-outline-dark"
                                href="{{ route('channels.edit', $channel->uid) }}"
                            >
                                <i class="fas fa-fw fa-edit"></i>
                            </a>

                            <form
                                action="{{ route('channels.destroy', $channel->uid) }}"
                                method="POST"
                                onsubmit="return confirm('Are you sure?')"
                            >
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

    <h5 class="fs-6 text-muted mb-3">Channel Providers</h5>

    <div class="row g-3">
        @forelse ($channelProviders as $provider)

            @include('userinterface::components.card-item', [
                'type'        => 'provider',
                'key'         => $provider->small_name,
                'title'       => $provider->name,
                'description' => $provider->getMeta('description'),
                'icon'        => $provider->getMeta('icon'),
                'provider'    => $provider,
            ])

        @empty
            <p class="text-muted">No channel providers found.</p>
        @endforelse
    </div>

</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function () {
        $('#channels-table').DataTable({
            responsive: true,
            order: [[2, 'desc']]
        });
    });
</script>
@endpush