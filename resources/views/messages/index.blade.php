@extends('userinterface::layouts.app')

@section('content')

<div class="container-fluid">

    <h5 class="fs-6 text-muted mb-3">Messages</h5>

    {{-- NUMBER FILTER --}}
    <form method="GET" class="row g-2 mb-2" id="numberForm">
        <input type="hidden" name="contact" id="hiddenContact" value="{{ $selectedContact }}">

        <div class="col-md-3">
            <select name="number" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select Your Number --</option>

                @foreach($numbers as $num)
                    <option value="{{ $num['number'] }}"
                        {{ $selectedNumber == $num['number'] ? 'selected' : '' }}>
                        {{ $num['number'] }}
                    </option>
                @endforeach
            </select>
        </div>
    </form>

    {{-- NO NUMBER SELECTED --}}
    @if(!$selectedNumber)
        <div class="alert alert-info">Please select your number to view messages.</div>
        @return
    @endif

    {{-- VIEW SWITCH --}}
    <div class="d-flex justify-content-end mb-3">
        <button class="btn btn-outline-primary btn-sm me-2" id="tableViewBtn" onclick="showView('table')">Table View</button>
        <button class="btn btn-outline-primary btn-sm" id="chatViewBtn" onclick="showView('chat')">Chat View</button>
    </div>

    {{-- TABLE VIEW --}}
    <div id="tableView">
        @if($allMessages->isEmpty())
            <div class="alert alert-info">No messages found for this number.</div>
        @else
            <table class="table table-bordered" id="messagesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th>Content</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($allMessages as $msg)
                        <tr>
                            <td>{{ $msg->id }}</td>
                            <td>{{ $msg->from }}</td>
                            <td>{{ $msg->to }}</td>
                            <td>{{ $msg->message_type }}</td>
                            <td>{{ $msg->content }}</td>
                            <td>{{ $msg->timestamp }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- CHAT VIEW (WhatsApp Style) --}}
    <div id="chatView" class="d-none">
        <div class="row" style="height: 600px;">
            
            {{-- LEFT SIDEBAR - CONTACTS LIST --}}
            <div class="col-md-4 border-end" style="height: 100%; overflow-y: auto; background: #f8f9fa;">
                <div class="p-2 border-bottom bg-white">
                    <h5 class="mb-0">Chats</h5>
                </div>

                @if(count($contacts) == 0)
                    <div class="p-3 text-muted text-center">
                        No conversations yet
                    </div>
                @else
                    @foreach($contacts as $contact)
                        <div class="contact-item p-3 border-bottom bg-white {{ $selectedContact == $contact['number'] ? 'active' : '' }}"
                             data-contact="{{ $contact['number'] }}"
                             style="cursor: pointer; transition: background 0.2s;"
                             onclick="selectContact('{{ $contact['number'] }}')">
                            
                            <div class="d-flex align-items-center">
                                {{-- Avatar --}}
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3"
                                     style="width: 50px; height: 50px; flex-shrink: 0;">
                                    <strong>{{ substr($contact['number'], -2) }}</strong>
                                </div>

                                {{-- Contact Info --}}
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="text-dark">{{ $contact['number'] }}</strong>
                                        <small class="text-muted">
                                            @php
                                                $lastTime = \Carbon\Carbon::parse($contact['last_timestamp']);
                                            @endphp

                                            {{ $lastTime->isToday() ? $lastTime->format('H:i') : $lastTime->format('Y-m-d') }}
                                        </small>
                                    </div>
                                    <div class="text-muted small text-truncate" style="max-width: 200px;">
                                        {{ $contact['last_message'] }}
                                    </div>
                                </div>
                            </div>

                        </div>
                    @endforeach
                @endif
            </div>

            {{-- RIGHT SIDE - CHAT MESSAGES --}}
            <div class="col-md-8 d-flex flex-column" style="height: 100%;">
                
                @if(!$selectedContact)
                    {{-- NO CONTACT SELECTED --}}
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                        <div class="text-center">
                            <i class="bi bi-chat-dots" style="font-size: 4rem;"></i>
                            <p class="mt-3">Select a contact to view conversation</p>
                        </div>
                    </div>
                @else
                    {{-- CHAT HEADER --}}
                    <div class="p-3 border-bottom bg-light">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3"
                                 style="width: 40px; height: 40px;">
                                <strong>{{ substr($selectedContact, -2) }}</strong>
                            </div>
                            <div>
                                <strong>{{ $selectedContact }}</strong>
                            </div>
                        </div>
                    </div>

                    {{-- MESSAGES AREA --}}
                    <div class="flex-grow-1 p-3" style="overflow-y: auto; background: #e5ddd5;" id="messagesContainer">
                        
                        @if($messages->isEmpty())
                            <div class="text-center text-muted">No messages yet</div>
                        @else
                            @foreach($messages as $msg)
                                @php
                                    $isFromMe = $msg->from == $selectedNumber;
                                @endphp

                                <div class="mb-2 d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }}">
                                    <div class="p-2 rounded shadow-sm text-break" style="max-width: 60%; background: {{ $isFromMe ? '#dcf8c6' : '#ffffff' }}">
                                        
                                        <div>{{ $msg->content }}</div>
                                        
                                        <div class="small text-muted mt-1 text-end">
                                            @php
                                                $msgTime = \Carbon\Carbon::parse($msg->timestamp);
                                            @endphp

                                            {{ $msgTime->isToday() ? $msgTime->format('H:i') : $msgTime->format('Y-m-d H:i') }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif

                    </div>

                    {{-- MESSAGE INPUT (Optional - for display only) --}}
                    {{-- <div class="p-3 border-top bg-light">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Type a message..." disabled>
                            <button class="btn btn-primary" disabled>Send</button>
                        </div>
                    </div> --}}
                @endif

            </div>

        </div>
    </div>

</div>

@endsection

@push('scripts')
    <script>
        let currentView = 'table';

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
    </script>
@endpush