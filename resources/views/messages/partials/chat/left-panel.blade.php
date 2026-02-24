    {{-- LEFT SIDEBAR --}}
    <div class="col-md-4 border-end d-flex flex-column p-0"
            style="height: 100%;">

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
                                    <p class="small fw-semibold mb-0 text-truncate d-flex align-items-center gap-1">
                                        {{ $contact['name'] }}

                                        @if(!empty($contact['is_agent']))

                                            @if(!empty($contact['is_active_agent']))
                                                {{-- Active Agent --}}
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="fa-solid fa-headset"></i>
                                                </span>
                                            @else
                                                {{-- Inactive Agent --}}
                                                <span class="badge bg-dark-subtle text-dark border border-dark-subtle">
                                                    <i class="fa-solid fa-headset"></i>
                                                </span>
                                            @endif

                                        @endif
                                    </p>
                                    <small class="text-muted ms-2 flex-shrink-0" style="font-size:10px;">
                                        {{ \Iquesters\Foundation\Helpers\DateTimeHelper::displaySmart($contact['last_timestamp']) }}
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
