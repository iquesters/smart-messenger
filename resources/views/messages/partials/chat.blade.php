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
                        data-number="{{ $contact['number'] }}"
                        data-name="{{ $contact['name'] }}"
                        data-message="{{ $contact['last_message'] ?? '' }}"
                        data-provider="{{ $contact['provider_name'] ?? '' }}"
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
            <div id="chatOptions" class="flex-grow-1 overflow-auto d-none bg-white">
                <!-- New Contact Button -->
                <button class="btn d-flex align-items-center justify-content-start gap-2 w-100 mb-2 shadow-sm bg-white border rounded-3 p-3"
                        id="newContactBtn"
                        type="button">
                    <span class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                        style="width:32px; height:32px;">
                        <i class="fa-solid fa-user-plus"></i>
                    </span>
                    New Contact
                </button>

                <!-- New Group Button -->
                <button class="btn d-flex align-items-center justify-content-start gap-2 w-100 mb-2 shadow-sm bg-white border rounded-3 p-3"
                        id="newGroupBtn"
                        type="button">
                    <span class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                        style="width:32px; height:32px;">
                        <i class="fa-solid fa-user-group"></i>
                    </span>
                    New Group
                </button>

                <!-- All Contacts List -->
                <div class="mt-3">
                    <div class="px-3 py-2 text-muted small fw-semibold">ALL CONTACTS</div>
                    <div id="allContactsList">
                        <!-- Contacts will be loaded here via AJAX -->
                        <div class="p-3 text-center text-muted">
                            <div class="spinner-border spinner-border-sm mb-2" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <div class="small">Loading contacts...</div>
                        </div>
                    </div>
                </div>
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
            <div class="d-flex align-items-center justify-content-center h-100 text-muted w-100" id="emptyState">
                Select a contact to view conversation
            </div>
        @else

        {{-- CHAT PANEL --}}
        <div class="flex-grow-1 d-flex flex-column" id="chatPanel">

            {{-- Header (CLICKABLE) --}}
            <div class="p-2 border-bottom bg-light d-flex align-items-center"
                style="cursor:pointer;"
                id="chatHeader">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-2"
                    style="width:45px;height:45px;">
                    <strong id="chatHeaderInitials">{{ substr($selectedContact, -2) }}</strong>
                </div>
                <strong id="chatHeaderName">{{ $selectedContactName }}</strong>
            </div>

            {{-- Messages --}}
            <div class="flex-grow-1 p-3 overflow-auto"
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
                        @php
                            $bubbleWidth = $msg->isText() ? '60%' : '30%';
                        @endphp

                        <div style="max-width: {{ $bubbleWidth }};" class="overflow-hidden">
                            <div class="p-2 rounded-3 shadow-sm text-break
                                {{ $isFromMe ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-body' }}"
                                style="word-wrap: break-word; overflow-wrap: break-word;">
                                
                                @if ($msg->isText())
                                    {{ $msg->content }}

                                @elseif ($msg->isMedia())
                                    @php
                                        $mediaUrl = $msg->mediaUrl();
                                        $caption  = $msg->caption();
                                    @endphp

                                    @if ($mediaUrl)
                                        {{-- IMAGE --}}
                                        @if ($msg->message_type === 'image')
                                             <div class="media-wrapper mb-1">
                                                <img src="{{ $mediaUrl }}" class="img-fluid w-100 rounded-top" />
                                            </div>

                                        {{-- VIDEO --}}
                                        @elseif ($msg->message_type === 'video')
                                            <video controls class="w-100 rounded mb-1">
                                                <source src="{{ $mediaUrl }}">
                                            </video>

                                        {{-- AUDIO --}}
                                        @elseif ($msg->message_type === 'audio')
                                            <audio controls class="w-100 mb-1">
                                                <source src="{{ $mediaUrl }}">
                                            </audio>

                                        {{-- DOCUMENT --}}
                                        @else
                                            <a href="{{ $mediaUrl }}" target="_blank" class="d-block text-decoration-none">
                                                📎 Download file
                                            </a>
                                        @endif
                                    @endif

                                    {{-- Caption --}}
                                    @if ($caption)
                                        <div class="media-caption px-2 py-1 small">
                                            {!! $msg->formattedCaption() !!}
                                        </div>
                                    @endif
                                @endif

                                <div class="text-end text-muted mt-1" style="font-size:10px;">
                                    {{ $msgTime->format('H:i') }}
                                </div>
                            </div>
                        </div>

                        {{-- Outgoing avatar --}}
                        @if($isFromMe)
                            <div class="ms-2 flex-shrink-0">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                    style="width:24px;height:24px;font-size:10px;">
                                    {{ substr($selectedNumber, -2) }}
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Currently, the setup depends on the environment. Going forward, it needs to be configuration-based. --}}
                    @if(config('app.env') === 'dev')
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
                    @endif

                    {{-- SENT BY --}}
                    @if($isFromMe)
                        <div class="d-flex justify-content-end" style="font-size:12px;">
                            <span class="fw-semibold text-success">{{ $msg->creator->name ?? 'System' }}</span>
                        </div>
                    @endif

                @endforeach
            </div>

            {{-- Input --}}
            <form id="sendMessageForm" class="d-flex align-items-center gap-2 p-2 border border-top">
                @csrf
                <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                <input type="hidden" name="to" id="messageTo" value="{{ $selectedContact }}">

                <!-- Plus -->
                <button type="button"
                        class="btn btn-sm rounded-circle bg-secondary-subtle text-primary flex-shrink-0 p-2">
                    <i class="fas fa-plus"></i>
                </button>

                <!-- Input -->
                <div class="flex-grow-1 position-relative">
                    <input type="text"
                        name="message"
                        class="form-control rounded-pill pe-5 bg-secondary-subtle py-2 px-3 border border-primary"
                        placeholder="Reply with message..."
                        required>

                    <!-- Icons inside input -->
                    <div class="position-absolute top-50 end-0 translate-middle-y pe-3 d-flex gap-2 z-3">
                        <button type="button" class="btn btn-sm p-1 border-0 bg-transparent">
                            <i class="far fa-fw fa-smile"></i>
                        </button>
                        <button type="button" class="btn btn-sm p-1 border-0 bg-transparent">
                            <i class="fas fa-fw fa-upload"></i>
                        </button>
                        <button type="button" class="btn btn-sm p-1 border-0 bg-transparent">
                            <i class="fas fa-fw fa-microphone"></i>
                        </button>
                    </div>
                </div>

                <!-- Schedule Send -->
                <div class="dropdown flex-shrink-0">
                    <button type="button"
                            class="btn btn-sm rounded-circle bg-secondary-subtle dropdown-toggle p-2"
                            data-bs-toggle="dropdown">
                        <i class="far fa-clock"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#">Send later</a></li>
                        <li><a class="dropdown-item" href="#">Pick date & time</a></li>
                    </ul>
                </div>

                <!-- Submit -->
                <button type="submit"
                        class="btn btn-sm rounded-circle bg-secondary-subtle text-primary flex-shrink-0 p-2">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>

        </div>

        @endif

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
                    style="width:80px;height:80px;font-size:24px;" id="detailsInitials">
                    {{ substr($selectedContact ?? '', -2) }}
                </div>

                <h6 class="mt-2" id="detailsNumber">{{ $selectedContact }}</h6>

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
                                <p class="text-muted" id="detailsPhone">{{ $selectedContact }}</p>

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

    </div>
</div>

@push('scripts')
<script>
    // Country codes data - fetched dynamically
    let countriesData = [];
    let allContactsData = [];
    
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
            } else if (view === 'options') {
                chatSearchInput.placeholder = 'Search contacts...';
            } else {
                chatSearchInput.placeholder = 'Search';
            }
        }

        chatSearchInput.value = '';
        
        document.querySelectorAll('.contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.group-contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.all-contact-item').forEach(item => item.style.display = '');

        switch(view) {
            case 'contacts':
                contactsView.classList.remove('d-none');
                break;
            case 'options':
                chatOptions.classList.remove('d-none');
                loadAllContacts(); // Load all contacts when showing options
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

    // Load all contacts via AJAX
    async function loadAllContacts() {
        const container = document.getElementById('allContactsList');
        
        try {
            const response = await fetch('/api/smart-messenger/contacts', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.data) {
                allContactsData = data.data;
                renderAllContacts(data.data);
            } else {
                container.innerHTML = `
                    <div class="p-3 text-center text-muted">
                        <div class="small">No contacts found</div>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading contacts:', error);
            container.innerHTML = `
                <div class="p-3 text-center text-muted">
                    <div class="small text-danger">Failed to load contacts</div>
                    <button class="btn btn-sm btn-link" onclick="loadAllContacts()">Retry</button>
                </div>
            `;
        }
    }

    // Render all contacts in the new chat view
    function renderAllContacts(contacts) {
        const container = document.getElementById('allContactsList');
        
        if (!contacts || contacts.length === 0) {
            container.innerHTML = `
                <div class="p-4 text-center text-muted">
                    <div class="display-4 mb-3 opacity-25">📭</div>
                    <div class="small">No contacts found</div>
                </div>
            `;
            return;
        }
        
        container.innerHTML = contacts.map(contact => {
            const lastTwo = contact.identifier.slice(-2);
            const providerIcon = contact.meta?.profile_details?.provider?.icon || '';
            
            return `
                <div class="all-contact-item p-3 border-bottom bg-white hover-bg-light" 
                     style="cursor:pointer;"
                     data-identifier="${escapeHtml(contact.identifier)}"
                     data-name="${escapeHtml(contact.name)}"
                     onclick="openContactChat('${escapeHtml(contact.identifier)}', '${escapeHtml(contact.name)}')">
                    
                    <div class="d-flex align-items-center w-100 overflow-hidden">
                        
                        <div class="position-relative me-2 flex-shrink-0">
                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                style="width:45px;height:45px;">
                                <strong>${escapeHtml(lastTwo)}</strong>
                            </div>
                            
                            ${providerIcon ? `
                                <small class="position-absolute text-muted bg-white rounded-circle d-flex align-items-center justify-content-center"
                                    style="width:20px;height:20px;right:0;bottom:0;">
                                    ${providerIcon}
                                </small>
                            ` : ''}
                        </div>
                        
                        <div class="flex-grow-1" style="min-width:0;">
                            <p class="small fw-semibold mb-0 text-truncate">
                                ${escapeHtml(contact.name)}
                            </p>
                            <div class="text-muted small text-truncate">
                                ${escapeHtml(contact.identifier)}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Open chat with a contact
    function openContactChat(identifier, name) {
        // Hide empty state if visible
        const emptyState = document.getElementById('emptyState');
        if (emptyState) {
            emptyState.classList.add('d-none');
        }
        
        // Show/create chat panel
        let chatPanel = document.getElementById('chatPanel');
        if (!chatPanel) {
            chatPanel = document.createElement('div');
            chatPanel.id = 'chatPanel';
            chatPanel.className = 'flex-grow-1 d-flex flex-column';
            document.querySelector('.col-md-8').appendChild(chatPanel);
        } else {
            chatPanel.classList.remove('d-none');
        }
        
        // Update chat header
        const chatHeaderInitials = document.getElementById('chatHeaderInitials');
        const chatHeaderName = document.getElementById('chatHeaderName');
        if (chatHeaderInitials) chatHeaderInitials.textContent = identifier.slice(-2);
        if (chatHeaderName) chatHeaderName.textContent = name;
        
        // Update details panel
        const detailsInitials = document.getElementById('detailsInitials');
        const detailsNumber = document.getElementById('detailsNumber');
        const detailsPhone = document.getElementById('detailsPhone');
        if (detailsInitials) detailsInitials.textContent = identifier.slice(-2);
        if (detailsNumber) detailsNumber.textContent = identifier;
        if (detailsPhone) detailsPhone.textContent = identifier;
        
        // Update message form
        const messageTo = document.getElementById('messageTo');
        if (messageTo) messageTo.value = identifier;
        
        // Clear messages container - show empty state for new conversation
        const messagesContainer = document.getElementById('messagesContainer');
        if (messagesContainer) {
            messagesContainer.innerHTML = `
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    <div class="text-center">
                        <div class="display-1 mb-3 opacity-25">💬</div>
                        <h6 class="fs-6 text-muted mb-2">No messages yet</h6>
                        <p class="text-muted small">Start the conversation with ${escapeHtml(name)}</p>
                    </div>
                </div>
            `;
        }
        
        // Navigate back to contacts view
        showView('contacts');
        
        // You can add AJAX call here to fetch existing messages if needed
        // fetchMessagesForContact(identifier);
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
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
                const number = item.dataset.number || '';
                const name = item.dataset.name || '';
                const message = item.dataset.message || '';
                const provider = item.dataset.provider || '';
                
                const match =
                    number.toLowerCase().includes(q) ||
                    name.toLowerCase().includes(q) ||
                    message.toLowerCase().includes(q) ||
                    provider.toLowerCase().includes(q);

                item.style.display = match ? '' : 'none';
            });
        }
        
        if (currentView === 'options') {
            document.querySelectorAll('.all-contact-item').forEach(item => {
                const identifier = item.dataset.identifier || '';
                const name = item.dataset.name || '';
                
                const match = 
                    identifier.toLowerCase().includes(q) ||
                    name.toLowerCase().includes(q);
                    
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

    // Contact creation
    window.currentMessagingProfileId = @json(
        collect($numbers)
            ->firstWhere('number', $selectedNumber)['profile_id']
            ?? null
    );
    const saveContactBtn = document.getElementById('saveContactBtn');

    if (saveContactBtn) {
        saveContactBtn.addEventListener('click', async function () {

            const name = document.getElementById('contactName').value.trim();
            const number = document.getElementById('contactNumber').value.trim();
            const countryCode = document
                .getElementById('selectedCode')
                .innerText.replace('+', '');

            if (!name || !number) {
                alert('Name and number are required');
                return;
            }

            if (!window.currentMessagingProfileId) {
                alert('Messaging profile not selected');
                return;
            }

            const payload = {
                name: name,
                identifier: countryCode + number,
                messaging_profile_id: window.currentMessagingProfileId
            };

            saveContactBtn.disabled = true;
            saveContactBtn.innerText = 'Saving...';

            try {
                const response = await fetch('/api/smart-messenger/contacts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .getAttribute('content')
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (!response.ok) {
                    throw result;
                }

                // ✅ Success
                alert('Contact created successfully');

                // Reset form
                document.getElementById('contactName').value = '';
                document.getElementById('contactNumber').value = '';

                // Go back to contacts list
                showView('contacts');

                // Reload to show new contact in chat list
                location.reload();

            } catch (error) {
                console.error(error);

                if (error.errors) {
                    alert(Object.values(error.errors).flat().join('\n'));
                } else {
                    alert(error.message || 'Failed to create contact');
                }
            } finally {
                saveContactBtn.disabled = false;
                saveContactBtn.innerText = 'Save Contact';
            }
        });
    }
</script>

<style>
    .hover-bg-light:hover {
        background-color: #f8f9fa;
    }
    
    .all-contact-item:hover {
        background-color: #f8f9fa !important;
    }
    
    .contact-item.active {
        background-color: #e7f1ff !important;
        border-left: 3px solid #0d6efd;
    }
</style>
@endpush