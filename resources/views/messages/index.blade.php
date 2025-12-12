@extends('userinterface::layouts.app')

@section('content')

<div class="container">

    <h4 class="mb-3">Messages</h4>

    {{-- NUMBER FILTER --}}
    <form method="GET" class="row g-2 mb-4">

        <div class="col-md-4">
            <select name="number" class="form-select">
                <option value="">-- Select Number --</option>

                @foreach($numbers as $num)
                    <option value="{{ $num['number'] }}"
                        {{ $selectedNumber == $num['number'] ? 'selected' : '' }}>
                        {{ $num['number'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2">
            <button class="btn btn-primary">Show</button>
        </div>
    </form>

    {{-- NO NUMBER SELECTED --}}
    @if(!$selectedNumber)
        <div class="alert alert-info">Please select a number to view messages.</div>
        @return
    @endif

    {{-- VIEW SWITCH --}}
    <div class="d-flex justify-content-end mb-3">
        <button class="btn btn-outline-primary btn-sm me-2" onclick="showView('table')">Table View</button>
        <button class="btn btn-outline-primary btn-sm" onclick="showView('chat')">Chat View</button>
    </div>

    {{-- TABLE VIEW --}}
    <div id="tableView">
        <table class="table table-bordered" id="messagesTable">
            <thead>
                <tr>
                    <th>Message ID</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Type</th>
                    <th>Content</th>
                    <th>Timestamp</th>
                </tr>
            </thead>

            <tbody>
                @foreach($messages as $msg)
                    <tr>
                        <td>{{ $msg->message_id }}</td>
                        <td>{{ $msg->from }}</td>
                        <td>{{ $msg->to }}</td>
                        <td>{{ $msg->message_type }}</td>
                        <td>{{ $msg->content }}</td>
                        <td>{{ $msg->timestamp }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- CHAT VIEW --}}
    <div id="chatView" class="d-none border p-3 rounded" style="height: 500px; overflow-y: auto;">

        @foreach($messages as $msg)
            <div class="mb-2 d-flex {{ $msg->from == $selectedNumber ? 'justify-content-start' : 'justify-content-end' }}">
                <div class="p-2 rounded"
                    style="max-width: 60%; 
                           background: {{ $msg->from == $selectedNumber ? '#f1f1f1' : '#d1e7ff' }}">
                    <strong>{{ $msg->from == $selectedNumber ? 'Them' : 'You' }}</strong><br>
                    {{ $msg->content }}
                    <div class="small text-muted mt-1">{{ $msg->timestamp }}</div>
                </div>
            </div>
        @endforeach

    </div>

</div>

@endsection

@push('scripts')
    <script>
        function showView(view) {
            document.getElementById('tableView').classList.toggle('d-none', view !== 'table');
            document.getElementById('chatView').classList.toggle('d-none', view !== 'chat');
        }
        $(document).ready(function() {
            $('#messagesTable').DataTable({
                responsive: true
            });
        });
    </script>
@endpush