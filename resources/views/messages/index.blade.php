@extends('userinterface::layouts.app')

@section('content')

<div class="container-fluid">

    <h5 class="fs-6 text-muted mb-2">Inbox</h5>

    {{-- NUMBER FILTER --}}
    @include('smartmessenger::messages.partials.number-filter')

    {{-- VIEW SWITCH --}}
    <div class="d-flex justify-content-end gap-2">
        <button class="btn btn-outline-primary btn-sm" id="chatViewBtn" onclick="showView('chat')">Chat View</button>
        <button class="btn btn-outline-primary btn-sm" id="tableViewBtn" onclick="showView('table')">Table View</button>
    </div>

    {{-- TABLE VIEW --}}
    @include('smartmessenger::messages.partials.table')

    {{-- CHAT VIEW (WhatsApp Style) --}}
    @include('smartmessenger::messages.partials.chat')

</div>

@endsection

@push('scripts')
    <script>
        let currentView = 'chat';

        function showView(view) {
            currentView = view;
            document.getElementById('tableView').classList.toggle('d-none', view !== 'table');
            document.getElementById('chatView').classList.toggle('d-none', view !== 'chat');
            
            // Update button styles
            document.getElementById('tableViewBtn').classList.toggle('btn-primary', view === 'table');
            document.getElementById('tableViewBtn').classList.toggle('btn-outline-primary', view !== 'table');
            document.getElementById('chatViewBtn').classList.toggle('btn-primary', view === 'chat');
            document.getElementById('chatViewBtn').classList.toggle('btn-outline-primary', view !== 'chat');

            // Scroll to bottom of messages when switching to chat view
            if (view === 'chat') {
                setTimeout(() => {
                    const container = document.getElementById('messagesContainer');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                }, 100);
            }
        }

        function selectContact(contactNumber) {
            // Set the hidden contact field
            document.getElementById('hiddenContact').value = contactNumber;
            
            // Submit the form to reload with the selected contact
            document.getElementById('numberForm').submit();
        }

        $(document).ready(function() {
            // Initialize DataTable only if table has data
            if ($('#messagesTable tbody tr').length > 0) {
                $('#messagesTable').DataTable({
                    responsive: true,
                    order: [[5, 'desc']] // Sort by timestamp column
                });
            }

            // If contact is selected, show chat view by default
            @if($selectedContact)
                showView('chat');
            @endif

            // Scroll to bottom of chat on page load
            const container = document.getElementById('messagesContainer');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });

        $('#sendMessageForm').on('submit', function(e) {
            e.preventDefault();

            const form = $(this);

            $.post("{{ route('messages.send') }}", form.serialize())
                .done(function () {
                    location.reload();
                })
                .fail(function () {
                    alert('Failed to send message');
                });
        });

        document.getElementById('chatSearch').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.contact-item').forEach(item => {
                const match =
                    item.dataset.number.includes(q) ||
                    item.dataset.message.includes(q) ||
                    item.dataset.provider.includes(q);

                item.style.display = match ? '' : 'none';
            });
        });

    </script>
@endpush