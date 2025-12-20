<div class="row" style="height: 600px;">

    {{-- LEFT SIDEBAR --}}
    <div class="col-md-4 border-end d-flex flex-column p-0"
            style="height: 100%; background: #f8f9fa;">

        @if(count($contacts) == 0)
            <div class="d-flex align-items-center justify-content-center h-100 text-muted w-100">
                No conversations yet
            </div>
        @else
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
                <div class="d-flex justify-content-start mt-2">
                    <div class="btn-group btn-group-sm gap-2" role="group" aria-label="Chat filter">
                        <button type="button"
                                class="btn btn-sm rounded-pill px-2 disabled"
                                id="groupFilter">
                            All
                        </button>
                        <button type="button"
                                class="btn btn-sm rounded-pill px-2 disabled active"
                                id="chatFilter">
                            Chat
                        </button>
                        <button type="button"
                                class="btn btn-sm rounded-pill disabled px-2"
                                id="groupFilter">
                            Group
                        </button>
                    </div>
                </div>
            </div>

            {{-- CONTACT LIST VIEW --}}
            <div id="contactsView" class="flex-grow-1 overflow-auto">
                @foreach($contacts as $contact)
                    <div class="contact-item p-3 border-bottom bg-white {{ $selectedContact == $contact['number'] ? 'active' : '' }}"
                        onclick="selectContact('{{ $contact['number'] }}')"
                        style="cursor:pointer;">

                        <div class="d-flex align-items-center w-100 overflow-hidden">

                            {{-- Avatar --}}
                            <div class="position-relative me-2 flex-shrink-0">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                    style="width:45px;height:45px;">
                                    <strong>{{ substr($contact['number'], -2) }}</strong>
                                </div>

                                <small class="position-absolute text-muted bg-white rounded-circle d-flex align-items-center justify-content-center"
                                    style="width:20px;height:20px;right:0;bottom:0;">
                                    {!! $contact['provider_icon'] ?? $contact['provider_name'] !!}
                                </small>
                            </div>

                            {{-- Text --}}
                            <div class="flex-grow-1" style="min-width:0;">

                                <div class="d-flex justify-content-between align-items-center">
                                    <p class="small fw-semibold mb-0 text-truncate">
                                        {{ $contact['name'] }}
                                    </p>
                                    <small class="text-muted ms-2 flex-shrink-0" style="font-size:10px;">
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
                    <div class="position-relative">
                        <div class="input-group">
                            <button class="btn btn-outline-secondary d-flex align-items-center gap-2" 
                                    type="button" 
                                    id="countryCodeBtn"
                                    style="min-width: 90px;">
                                <span id="selectedFlag">🇺🇸</span>
                                <span id="selectedCode">+1</span>
                                <i class="fas fa-caret-down"></i>
                            </button>
                            <input type="text" class="form-control" id="contactNumber" placeholder="Phone Number">
                        </div>
                        
                        {{-- Country Code Dropdown --}}
                        <div id="countryDropdown" class="position-absolute bg-white border rounded shadow-lg d-none" 
                                style="top: 100%; left: 0; width: 350px; max-height: 400px; z-index: 1050; margin-top: 5px;">
                            <div class="p-2 border-bottom sticky-top bg-white">
                                <input type="text" 
                                        class="form-control form-control-sm" 
                                        id="countrySearch" 
                                        placeholder="Search countries...">
                            </div>
                            <div id="countryList" class="overflow-auto" style="max-height: 350px;">
                                {{-- Countries will be loaded here dynamically --}}
                            </div>
                        </div>
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
         @endif
    </div>

    {{-- RIGHT CHAT PANEL --}}
    <div class="col-md-8 p-0 d-flex" style="height:100%;">

        @if(!$selectedContact)
            <div class="d-flex align-items-center justify-content-center h-100 text-muted w-100">
                Select a contact to view conversation
            </div>
        @else

        {{-- CHAT PANEL --}}
        <div class="flex-grow-1 d-flex flex-column">

            {{-- Header (CLICKABLE) --}}
            <div class="p-2 border-bottom bg-light d-flex align-items-center"
                style="cursor:pointer;"
                id="chatHeader">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-2"
                    style="width:45px;height:45px;">
                    <strong>{{ substr($selectedContact, -2) }}</strong>
                </div>
                <strong>{{ $selectedContactName }}</strong>
            </div>

            {{-- Messages --}}
            <div class="flex-grow-1 p-3 overflow-auto"
                style="background:#e5ddd5;"
                id="messagesContainer">

                @php
                    $lastDate = null;
                @endphp

                @foreach($messages as $index => $msg)
                    @php
                        $isFromMe = $msg->from == $selectedNumber;
                        $msgTime = \Carbon\Carbon::parse($msg->timestamp);
                        $msgDate = $msgTime->toDateString();
                        $isToday = $msgTime->isToday();
                    @endphp

                    {{-- DATE SEPARATOR (NOT TODAY, ONCE PER DAY) --}}
                    @if($msgDate !== $lastDate && !$isToday)
                        <div class="d-flex justify-content-center my-3">
                            <span class="badge bg-white text-muted px-3 py-2 shadow-sm">
                                {{ $msgTime->format('d M Y') }}
                            </span>
                        </div>
                    @endif

                    @php
                        $lastDate = $msgDate;
                    @endphp

                    {{-- MESSAGE --}}
                    <div class="mb-2 d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }} align-items-end">

                        {{-- Incoming avatar --}}
                        @if(!$isFromMe)
                            <div class="me-2 flex-shrink-0">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                    style="width:32px;height:32px;font-size:12px;">
                                    {{ substr($msg->from, -2) }}
                                </div>
                            </div>
                        @endif

                        {{-- Message bubble --}}
                        <div style="max-width:60%;" class="overflow-hidden">
                            <div class="p-2 rounded shadow-sm text-break"
                                style="
                                    background:{{ $isFromMe ? '#dcf8c6' : '#fff' }};
                                    word-wrap: break-word;
                                    overflow-wrap: break-word;
                                ">
                                {{ $msg->content }}

                                <div class="text-end text-muted mt-1" style="font-size:10px;">
                                    {{ $msgTime->format('H:i') }}
                                </div>
                            </div>
                        </div>

                        {{-- Outgoing avatar --}}
                        @if($isFromMe)
                            <div class="ms-2 flex-shrink-0">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                    style="width:32px;height:32px;font-size:12px;">
                                    {{ substr($selectedNumber, -2) }}
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- ================= DEV MODE (ONLY FOR RECEIVED MESSAGE) ================= --}}
                    @if(!$isFromMe)
                        <div class="d-flex justify-content-center mb-3">

                            <div class="accordion w-75" id="devModeAccordion-{{ $index }}">
                                <div class="accordion-item border-0">

                                    <h5 class="accordion-header">
                                        <button class="accordion-button collapsed py-1 px-2 bg-dark-subtle text-dark"
                                                style="font-size:.75rem;"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#devMode-{{ $index }}">
                                            <i class="fas fa-code me-1"></i> Dev Mode
                                        </button>
                                    </h5>

                                    <div id="devMode-{{ $index }}" class="accordion-collapse collapse">
                                        <div class="accordion-body small bg-light rounded">

                                            {{-- API REQUEST --}}
                                            <div class="d-flex align-items-end gap-2 mb-3">
                                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                                    style="width:25px;height:25px;font-size:.65rem;">
                                                    GB
                                                </div>

                                                <div>
                                                    <div class="fw-semibold text-info small">API Request</div>
                                                    @if(!empty($msg->api_request))
                                                        <pre class="mb-0 p-2 bg-white border rounded small">
                                                            {{ json_encode($msg->api_request, JSON_PRETTY_PRINT) }}
                                                        </pre>
                                                    @else
                                                        <div class="text-muted fst-italic small">
                                                            No API request data
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- API RESPONSE --}}
                                            <div class="text-end">
                                                <div class="fw-semibold text-success small">API Response</div>
                                                @if(!empty($msg->api_response))
                                                    <pre class="mb-0 p-2 bg-white border rounded small text-start">
                                                        {{ json_encode($msg->api_response, JSON_PRETTY_PRINT) }}
                                                    </pre>
                                                @else
                                                    <div class="text-muted fst-italic small">
                                                        No API response data
                                                    </div>
                                                @endif
                                            </div>

                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div>
                    @endif

                    {{-- SENT BY --}}
                    @if($isFromMe)
                        <div class="d-flex justify-content-end mb-2" style="font-size:12px;">
                            Sent by <span class="fw-semibold text-success ms-1">{{ auth()->user()->name }}</span>
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
        </div>

        {{-- DETAILS PANEL --}}
        <div id="detailsPanel"
            class="border-start bg-white d-none"
            style="width:300px; transition: all .3s ease;">

            {{-- Header --}}
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <strong>Info</strong>
                <button class="btn btn-sm btn-light" id="closeDetails">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            {{-- Content --}}
            <div class="p-2 text-center">
                <div class="rounded-circle bg-primary-subtle text-primary mx-auto d-flex align-items-center justify-content-center"
                    style="width:80px;height:80px;font-size:24px;">
                    {{ substr($selectedContact, -2) }}
                </div>

                <h6 class="mt-2">{{ $selectedContact }}</h6>

                <!-- Accordion -->
                <div class="accordion mt-3" id="contactDetailsAccordion">

                    <!-- Contact Details -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingContactDetails">
                            <button class="accordion-button p-2"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#collapseContactDetails"
                                aria-expanded="true"
                                aria-controls="collapseContactDetails">
                                Contact Details
                            </button>
                        </h2>

                        <div id="collapseContactDetails"
                            class="accordion-collapse collapse show"
                            aria-labelledby="headingContactDetails">
                            <div class="accordion-body text-start p-2">

                                <p class="mb-1">Phone</p>
                                <p class="text-muted">{{ $selectedContact }}</p>

                                <div class="d-flex flex-column align-items-center justify-content-center gap-2">
                                    <button class="btn text-dark btn-sm d-flex align-items-center justify-content-start gap-2 w-100 px-0">
                                        <i class="fas fa-fw fa-pen-to-square"></i> Edit Contact
                                    </button>
                                    <button class="btn text-danger btn-sm d-flex align-items-center justify-content-start gap-2 w-100 px-0">
                                        <i class="fas fa-fw fa-trash-can"></i> Delete Contact
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Integration Details -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingIntegrationDetails">
                            <button class="accordion-button p-2"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#collapseIntegrationDetails"
                                aria-expanded="true"
                                aria-controls="collapseIntegrationDetails">
                                Integration Details
                            </button>
                        </h2>

                        <div id="collapseIntegrationDetails"
                            class="accordion-collapse collapse show"
                            aria-labelledby="headingIntegrationDetails">
                            <div class="accordion-body text-start p-2">

                                <ul class="list-unstyled mb-0">
                                    <li class="d-flex align-items-center gap-2 mb-2">
                                        <i class="fas fa-cart-shopping text-primary"></i>
                                        <span>WooCommerce</span>
                                    </li>
                                    <li class="d-flex align-items-center gap-2 mb-2">
                                        <i class="fab fa-shopify text-success"></i>
                                        <span>Shopify</span>
                                    </li>
                                    <li class="d-flex align-items-center gap-2">
                                        <i class="fas fa-plug text-muted"></i>
                                        <span>Other Integrations</span>
                                    </li>
                                </ul>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        @endif
    </div>
</div>

@push('scripts')
<script>
    // Country codes data - fetched dynamically
    let countriesData = [];
    
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

    const chatHeader = document.getElementById('chatHeader');
    const detailsPanel = document.getElementById('detailsPanel');
    const closeDetails = document.getElementById('closeDetails');

    // Country code elements
    const countryCodeBtn = document.getElementById('countryCodeBtn');
    const countryDropdown = document.getElementById('countryDropdown');
    const countrySearch = document.getElementById('countrySearch');
    const countryList = document.getElementById('countryList');
    const selectedFlag = document.getElementById('selectedFlag');
    const selectedCode = document.getElementById('selectedCode');

    // Navigation state
    let currentView = 'contacts';

    // Load countries on page load
    loadCountries();

    async function loadCountries() {
        try {
            const response = await fetch('https://restcountries.com/v3.1/all');
            const countries = await response.json();
            
            // Process and sort countries
            countriesData = countries
                .filter(country => country.idd && country.idd.root)
                .map(country => ({
                    name: country.name.common,
                    flag: country.flag,
                    code: country.idd.root + (country.idd.suffixes ? country.idd.suffixes[0] : ''),
                    callingCode: country.idd.root + (country.idd.suffixes ? country.idd.suffixes[0] : '')
                }))
                .sort((a, b) => a.name.localeCompare(b.name));
            
            renderCountries(countriesData);
        } catch (error) {
            console.error('Error loading countries:', error);
            // Fallback to basic list if API fails
            countriesData = [
                { name: 'United States', flag: '🇺🇸', code: '+1' },
                { name: 'United Kingdom', flag: '🇬🇧', code: '+44' },
                { name: 'India', flag: '🇮🇳', code: '+91' },
                { name: 'Canada', flag: '🇨🇦', code: '+1' }
            ];
            renderCountries(countriesData);
        }
    }

    function renderCountries(countries) {
        countryList.innerHTML = countries.map(country => `
            <div class="country-item d-flex align-items-center p-2 hover-bg-light" 
                 style="cursor: pointer;"
                 data-flag="${country.flag}" 
                 data-code="${country.code}">
                <span style="font-size: 1.2rem; margin-right: 8px;">${country.flag}</span>
                <span class="flex-grow-1">${country.name}</span>
                <span class="text-muted">${country.code}</span>
            </div>
        `).join('');

        // Add click handlers to country items
        document.querySelectorAll('.country-item').forEach(item => {
            item.addEventListener('click', function() {
                const flag = this.dataset.flag;
                const code = this.dataset.code;
                selectCountryCode(flag, code);
            });
        });
    }

    function selectCountryCode(flag, code) {
        selectedFlag.textContent = flag;
        selectedCode.textContent = code;
        countryDropdown.classList.add('d-none');
    }

    // Toggle country dropdown
    if (countryCodeBtn) {
        countryCodeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            countryDropdown.classList.toggle('d-none');
        });
    }

    // Search countries
    if (countrySearch) {
        countrySearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const filteredCountries = countriesData.filter(country => 
                country.name.toLowerCase().includes(searchTerm) || 
                country.code.includes(searchTerm)
            );
            renderCountries(filteredCountries);
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!countryDropdown.contains(e.target) && e.target !== countryCodeBtn) {
            countryDropdown.classList.add('d-none');
        }
    });

    if (chatHeader) {
        chatHeader.addEventListener('click', () => {
            detailsPanel.classList.remove('d-none');
        });
    }

    if (closeDetails) {
        closeDetails.addEventListener('click', () => {
            detailsPanel.classList.add('d-none');
        });
    }

    function showView(view, title = 'New Chat') {
        contactsView.classList.add('d-none');
        chatOptions.classList.add('d-none');
        newContactForm.classList.add('d-none');
        newGroupForm.classList.add('d-none');

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
            
            if (view === 'newGroup') {
                chatSearchInput.placeholder = 'Search contacts...';
            } else {
                chatSearchInput.placeholder = 'Search';
            }
        }

        chatSearchInput.value = '';
        
        document.querySelectorAll('.contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.group-contact-item').forEach(item => item.style.display = '');

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

    newChatBtn.addEventListener('click', () => {
        showView('options', 'New Chat');
    });

    backBtn.addEventListener('click', () => {
        if (currentView === 'newContact' || currentView === 'newGroup') {
            showView('options', 'New Chat');
        } else if (currentView === 'options') {
            showView('contacts');
        }
    });

    newContactBtn.addEventListener('click', () => {
        showView('newContact', 'New Contact');
    });

    newGroupBtn.addEventListener('click', () => {
        showView('newGroup', 'New Group');
    });

    chatSearchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        
        if (currentView === 'contacts') {
            document.querySelectorAll('.contact-item').forEach(item => {
                const match =
                    item.dataset.number.includes(q) ||
                    item.dataset.message.includes(q) ||
                    item.dataset.provider.includes(q);

                item.style.display = match ? '' : 'none';
            });
        }
        
        if (currentView === 'newGroup') {
            document.querySelectorAll('.group-contact-item').forEach(item => {
                const match = item.dataset.contactName.includes(q);
                item.style.display = match ? '' : 'none';
            });
        }
    });

    function selectContact(contactNumber) {
        const contactInput = document.getElementById('selectedContactInput');
        const form = document.getElementById('numberForm');

        if (!contactInput || !form) {
            console.error('Contact input or form not found');
            return;
        }

        contactInput.value = contactNumber;
        form.submit();
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

<style>
    .hover-bg-light:hover {
        background-color: #f8f9fa;
    }
</style>
@endpush