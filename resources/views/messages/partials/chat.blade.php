<div id="chatView">
    <div class="row" style="height: 600px;">

        {{-- LEFT SIDEBAR --}}
        <div class="col-md-4 border-end d-flex flex-column"
             style="height: 100%; background: #f8f9fa;">

            {{-- Header --}}
            <div class="p-2 border-bottom bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2" id="mainHeader">
                    <h5 class="fs-6 text-muted mb-0">Chat</h5>
                    <button id="newChatBtn" class="btn bg-white btn-sm"><i class="fa-regular fa-fw fa-square-plus"></i></button>
                </div>

                {{-- Back Header (hidden by default) --}}
                <div class="d-flex justify-content-between align-items-center mb-2 d-none" id="backHeader">
                    <div class="d-flex align-items-center gap-2">
                        <button id="backBtn" class="btn btn-sm">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <p class="text-muted mb-0" id="backHeaderTitle">New Chat</p>
                    </div>
                </div>

                {{-- Search box --}}
                <div class="input-group input-group-sm" id="mainSearchBox">
                    <span class="input-group-text bg-white py-2">
                        <i class="fas fa-fw fa-search"></i>
                    </span>

                    <input type="text"
                           id="chatSearch"
                           class="form-control border-start-0 py-2"
                           placeholder="Search">
                </div>
            </div>

            {{-- CONTACT LIST VIEW --}}
            <div id="contactsView" class="flex-grow-1 overflow-auto">
                @if(count($contacts) == 0)
                    <div class="p-3 text-muted text-center">
                        No conversations yet
                    </div>
                @else
                    @foreach($contacts as $contact)
                        <div class="contact-item p-3 border-bottom bg-white {{ $selectedContact == $contact['number'] ? 'active' : '' }}"
                             data-number="{{ strtolower($contact['number']) }}"
                             data-message="{{ strtolower($contact['last_message']) }}"
                             data-provider="{{ strtolower($contact['provider_name']) }}"
                             onclick="selectContact('{{ $contact['number'] }}')"
                             style="cursor:pointer;">

                            <div class="d-flex align-items-center">
                                {{-- Avatar --}}
                                <div class="position-relative me-2" style="width:55px;">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width:45px;height:45px;">
                                        <strong>{{ substr($contact['number'], -2) }}</strong>
                                    </div>

                                    <small class="position-absolute text-muted"
                                           style="right:-5px;bottom:-8px;font-size:10px;white-space:nowrap;">
                                        {{ $contact['provider_name'] }}
                                    </small>
                                </div>

                                {{-- Info --}}
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <p class="small fw-semibold mb-0">{{ $contact['number'] }}</p>
                                        <small class="text-muted" style="font-size:10px;">
                                            {{ \Carbon\Carbon::parse($contact['last_timestamp'])->format('H:i') }}
                                        </small>
                                    </div>
                                    <div class="text-muted small text-truncate">
                                        {{ $contact['last_message'] }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- NEW CHAT OPTIONS VIEW --}}
            <div id="chatOptions" class="flex-grow-1 overflow-auto d-none bg-white p-3">
                <!-- New Contact Button -->
            <button class="btn d-flex align-items-center justify-content-start gap-2 w-100 mb-2 shadow-sm bg-white border rounded-3"
                    id="newContactBtn"
                    type="button">
                <span class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                    style="width:32px; height:32px;">
                    <i class="fa-solid fa-user-plus"></i>
                </span>
                New Contact
            </button>

            <!-- New Group Button -->
            <button class="btn d-flex align-items-center justify-content-start gap-2 w-100 shadow-sm bg-white border rounded-3"
                    id="newGroupBtn"
                    type="button">
                <span class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                    style="width:32px; height:32px;">
                    <i class="fa-solid fa-user-group"></i>
                </span>
                New Group
            </button>

            </div>

            {{-- NEW CONTACT FORM VIEW --}}
            <div id="newContactForm" class="flex-grow-1 overflow-auto d-none bg-white p-3">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" id="contactName" placeholder="Contact Name">
                </div>
                <div class="mb-3">
                    <label class="form-label">Number</label>
                    <div class="input-group">
                        <span class="input-group-text">+1</span>
                        <input type="text" class="form-control" id="contactNumber" placeholder="Phone Number">
                    </div>
                </div>
                <button class="btn btn-primary w-100" id="saveContactBtn">Save Contact</button>
            </div>

            {{-- NEW GROUP FORM VIEW --}}
            <div id="newGroupForm" class="flex-grow-1 overflow-auto d-none bg-white p-3">
                <div id="groupContactsList" class="overflow-auto" style="max-height: 450px;">
                    @foreach($contacts as $contact)
                        <div class="form-check mb-1 group-contact-item" data-contact-name="{{ strtolower($contact['number']) }}">
                            <input class="form-check-input" type="checkbox" value="{{ $contact['number'] }}" id="groupContact{{ $loop->index }}">
                            <label class="form-check-label" for="groupContact{{ $loop->index }}">{{ $contact['number'] }}</label>
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-primary w-100 mt-2" id="createGroupBtn">Create Group</button>
            </div>

        </div>

        {{-- RIGHT CHAT PANEL --}}
        <div class="col-md-8 d-flex flex-column" style="height:100%;">
            @if(!$selectedContact)
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    Select a contact to view conversation
                </div>
            @else
                {{-- Header --}}
                <div class="p-3 border-bottom bg-light d-flex align-items-center">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2"
                         style="width:45px;height:45px;">
                        <strong>{{ substr($selectedContact, -2) }}</strong>
                    </div>
                    <strong>{{ $selectedContact }}</strong>
                </div>

                {{-- Messages --}}
                <div class="flex-grow-1 p-3 overflow-auto"
                     style="background:#e5ddd5;"
                     id="messagesContainer">

                    @foreach($messages as $msg)
                        @php $isFromMe = $msg->from == $selectedNumber; @endphp

                        <div class="mb-2 d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }} align-items-end">

                            {{-- Incoming avatar --}}
                            @if(!$isFromMe)
                                <div class="me-2">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width:32px;height:32px;font-size:12px;">
                                        {{ substr($msg->from, -2) }}
                                    </div>
                                </div>
                            @endif

                            {{-- Bubble --}}
                            <div class="p-2 rounded shadow-sm"
                                style="
                                    max-width:60%;
                                    background:{{ $isFromMe ? '#dcf8c6' : '#fff' }};
                                    word-wrap: break-word;
                                    overflow-wrap: break-word;
                                ">
                                <div class="mb-1">
                                    {{ $msg->content }}
                                </div>

                                @php
                                    $msgTime = \Carbon\Carbon::parse($msg->timestamp);
                                    $senderName = auth()->user()->name;
                                @endphp

                                <div class="text-end text-muted" style="font-size:10px;">
                                    {{ $msgTime->format('H:i') }}
                                    @if(!$msgTime->isToday())
                                        <br>
                                        {{ $msgTime->format('d M Y') }}
                                    @endif
                                </div>
                            </div>

                            {{-- Outgoing avatar --}}
                            @if($isFromMe)
                                <div class="ms-2">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width:32px;height:32px;font-size:12px;">
                                        {{ substr($selectedNumber, -2) }}
                                    </div>
                                </div>
                            @endif

                        </div>
                        @if($isFromMe)
                        <div class="d-flex align-items-center justify-content-end mb-1 gap-1" style="font-size:12px;">
                            Sent by:
                            <span class="fw-semibold text-success" >
                                {{ $senderName }}
                            </span>
                        </div>
                        @endif
                    @endforeach
                </div>

                {{-- Input --}}
                <div class="p-3 border-top" style="background:#e5ddd5;">
                    <form id="sendMessageForm">
                        @csrf
                        <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                        <input type="hidden" name="to" value="{{ $selectedContact }}">

                        <div class="input-group">
                            <input type="text" name="message" class="form-control p-2"
                                   placeholder="Reply with message..." required>
                            <button class="btn btn-sm bg-white text-primary">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
 <script>
    // Elements
    const mainHeader = document.getElementById('mainHeader');
    const backHeader = document.getElementById('backHeader');
    const backHeaderTitle = document.getElementById('backHeaderTitle');
    const mainSearchBox = document.getElementById('mainSearchBox');
    const newChatBtn = document.getElementById('newChatBtn');
    const backBtn = document.getElementById('backBtn');
    
    const contactsView = document.getElementById('contactsView');
    const chatOptions = document.getElementById('chatOptions');
    const newContactForm = document.getElementById('newContactForm');
    const newGroupForm = document.getElementById('newGroupForm');
    
    const newContactBtn = document.getElementById('newContactBtn');
    const newGroupBtn = document.getElementById('newGroupBtn');
    const chatSearchInput = document.getElementById('chatSearch');

    // Navigation state
    let currentView = 'contacts'; // 'contacts', 'options', 'newContact', 'newGroup'

    // Show view with header update
    function showView(view, title = 'New Chat') {
        // Hide all views
        contactsView.classList.add('d-none');
        chatOptions.classList.add('d-none');
        newContactForm.classList.add('d-none');
        newGroupForm.classList.add('d-none');

        // Update header
        if (view === 'contacts') {
            mainHeader.classList.remove('d-none');
            backHeader.classList.add('d-none');
            mainSearchBox.classList.remove('d-none');
            chatSearchInput.placeholder = 'Search';
        } else {
            mainHeader.classList.add('d-none');
            backHeader.classList.remove('d-none');
            backHeaderTitle.textContent = title;
            mainSearchBox.classList.remove('d-none');
            
            // Update placeholder based on view
            if (view === 'newGroup') {
                chatSearchInput.placeholder = 'Search contacts...';
            } else {
                chatSearchInput.placeholder = 'Search';
            }
        }

        // Clear search input when changing views
        chatSearchInput.value = '';
        
        // Reset all items visibility
        document.querySelectorAll('.contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.group-contact-item').forEach(item => item.style.display = '');

        // Show selected view
        switch(view) {
            case 'contacts':
                contactsView.classList.remove('d-none');
                break;
            case 'options':
                chatOptions.classList.remove('d-none');
                break;
            case 'newContact':
                newContactForm.classList.remove('d-none');
                break;
            case 'newGroup':
                newGroupForm.classList.remove('d-none');
                break;
        }

        currentView = view;
    }

    // New Chat button - show options
    newChatBtn.addEventListener('click', () => {
        showView('options', 'New Chat');
    });

    // Back button - navigate to previous screen
    backBtn.addEventListener('click', () => {
        if (currentView === 'newContact' || currentView === 'newGroup') {
            // Go back to options
            showView('options', 'New Chat');
        } else if (currentView === 'options') {
            // Go back to contacts
            showView('contacts');
        }
    });

    // New Contact button
    newContactBtn.addEventListener('click', () => {
        showView('newContact', 'New Contact');
    });

    // New Group button
    newGroupBtn.addEventListener('click', () => {
        showView('newGroup', 'New Group');
    });

    // Unified search functionality
    chatSearchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        
        // Search in contacts view
        if (currentView === 'contacts') {
            document.querySelectorAll('.contact-item').forEach(item => {
                const match =
                    item.dataset.number.includes(q) ||
                    item.dataset.message.includes(q) ||
                    item.dataset.provider.includes(q);

                item.style.display = match ? '' : 'none';
            });
        }
        
        // Search in group creation view
        if (currentView === 'newGroup') {
            document.querySelectorAll('.group-contact-item').forEach(item => {
                const match = item.dataset.contactName.includes(q);
                item.style.display = match ? '' : 'none';
            });
        }
    });
    function selectContact(contactNumber) {
        // Set the hidden contact field
        document.getElementById('hiddenContact').value = contactNumber;
        
        // Submit the form to reload with the selected contact
        document.getElementById('numberForm').submit();
    }

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
</script>   
@endpush
