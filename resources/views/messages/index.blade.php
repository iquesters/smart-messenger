@extends('userinterface::layouts.app')

@section('content')
<div class="container-fluid">

    <h5 class="fs-6 text-muted mb-2">Inbox</h5>

    <div class="d-flex align-items-center justify-content-between mb-2">
        {{-- NUMBER FILTER --}}
        <div>
            @include('smartmessenger::messages.partials.number-filter')
        </div>

        {{-- VIEW SWITCH --}}
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-primary btn-sm" id="chatViewBtn">Chat View</button>
            <button class="btn btn-outline-primary btn-sm" id="tableViewBtn">Table View</button>
        </div>
    </div>

    {{-- CHAT VIEW (default) --}}
    <div id="chatView">
        @include('smartmessenger::messages.partials.chat')
    </div>

    {{-- TABLE VIEW --}}
    <div id="tableView" class="d-none mt-3">
        @include('smartmessenger::messages.partials.table')
    </div>

</div>
@endsection

@push('scripts')
<script>
    let tableInitialized = false;

    window.toggleView = function(view) {
        const chatEl = document.getElementById('chatView');
        const tableEl = document.getElementById('tableView');

        if(view === 'chat') {
            chatEl.classList.remove('d-none');
            tableEl.classList.add('d-none');

            document.getElementById('chatViewBtn').classList.add('btn-primary');
            document.getElementById('chatViewBtn').classList.remove('btn-outline-primary');
            document.getElementById('tableViewBtn').classList.add('btn-outline-primary');
            document.getElementById('tableViewBtn').classList.remove('btn-primary');

            // Scroll chat to bottom
            const container = document.getElementById('messagesContainer');
            if(container) container.scrollTop = container.scrollHeight;

        } else if(view === 'table') {
            chatEl.classList.add('d-none');
            tableEl.classList.remove('d-none');

            document.getElementById('tableViewBtn').classList.add('btn-primary');
            document.getElementById('tableViewBtn').classList.remove('btn-outline-primary');
            document.getElementById('chatViewBtn').classList.add('btn-outline-primary');
            document.getElementById('chatViewBtn').classList.remove('btn-primary');

            // Initialize DataTable only once
            if(!tableInitialized) {
                $('#messagesTable').DataTable({
                    responsive: true,
                    order: [[5, 'desc']]
                });
                tableInitialized = true;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Attach button click handlers
        document.getElementById('chatViewBtn').addEventListener('click', function() {
            toggleView('chat');
        });
        document.getElementById('tableViewBtn').addEventListener('click', function() {
            toggleView('table');
        });

        // Default to chat view
        toggleView('chat');

        // Scroll chat to bottom on load
        const container = document.getElementById('messagesContainer');
        if(container) container.scrollTop = container.scrollHeight;

        // Initialize tooltips for number filter
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

    // Number filter selection
    function selectNumber(number) {
        const input = document.getElementById('selectedNumberInput');
        if(input){
            input.value = number;
            document.getElementById('numberForm').submit();
        }
    }

</script>
@endpush