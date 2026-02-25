        @if(!$selectedContact)
            <div class="d-flex align-items-center justify-content-center h-100 text-muted w-100" id="emptyState">
                Select a contact to view conversation
            </div>
        @else

        @php
            $authUser = auth()->user();
            $isSuperAdmin = false;

            if ($authUser) {
                if (method_exists($authUser, 'hasRole')) {
                    $isSuperAdmin = $authUser->hasRole('super-admin');
                } elseif (method_exists($authUser, 'roles')) {
                    $isSuperAdmin = $authUser->roles()->where('name', 'super-admin')->exists();
                } elseif (isset($authUser->role)) {
                    $isSuperAdmin = $authUser->role === 'super-admin';
                }
            }
        @endphp

        {{-- CHAT PANEL --}}
        <div class="flex-grow-1 d-flex flex-column position-relative" id="chatPanel">

            {{-- Header (CLICKABLE) --}}
            <div class="p-2 border-bottom bg-light d-flex align-items-center justify-content-between"
                style="cursor:pointer;"
                id="chatHeader">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-2"
                        style="width:45px;height:45px;">
                        <strong id="chatHeaderInitials">{{ substr($selectedContact, -2) }}</strong>
                    </div>
                    <strong id="chatHeaderName">{{ $selectedContactName }}</strong>
                </div>
                <div class="d-flex align-items-center gap-2" onclick="event.stopPropagation();">
                    @if($isSuperAdmin)
                        <div class="form-check form-switch mb-0 d-inline-flex align-items-center gap-1 px-2">
                            <input
                                class="form-check-input mt-0"
                                type="checkbox"
                                role="switch"
                                id="devModeToggle"
                                data-is-super-admin="1"
                                style="cursor: pointer;">
                            <label class="form-check-label small fw-semibold text-muted mb-0" for="devModeToggle">Dev</label>
                        </div>
                    @endif

                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown">
                            Switch Agent
                        </button>

                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2 active"
                                href="#">
                                    <i class="fa-solid fa-check text-success"></i>
                                    Gautams Chatbot
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2"
                                href="#">
                                    <span></span>
                                    ChatGPT
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2"
                                href="#">
                                    <span></span>
                                    Human Agent
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

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
                    @if($msgDate !== $lastDate)
                        <div class="d-flex justify-content-center my-3">
                            <span class="badge bg-white text-dark fw-medium px-3 shadow-sm" style="font-size: 12px">
                                        {{ \Iquesters\Foundation\Helpers\DateTimeHelper::displaySmart($msgTime) }}
                            </span>
                        </div>
                    @endif

                    @php
                        $lastDate = $msgDate;
                    @endphp

                    {{-- MESSAGE --}}
                    <div class="mb-2 pt-2 d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }} align-items-start">

                        {{-- Incoming avatar --}}
                        @if(!$isFromMe)
                            <div class="me-2 flex-shrink-0">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                    style="width:24px;height:24px;font-size:10px;">
                                    {{ substr($msg->from, -2) }}
                                </div>
                            </div>
                        @endif

                        {{-- Message wrapper --}}
                        @php
                            $bubbleWidth = $msg->isText() ? '60%' : '30%';
                        @endphp
                        <div style="max-width: {{ $bubbleWidth }}; {{ $isFromMe ? 'margin-left: auto;' : '' }}" class="overflow-hidden">

                            {{-- Time & Sender --}}
                            <div class="d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }} gap-2" style="font-size:10px;">
                                
                                @if($isFromMe)
                                    <span class="fw-semibold text-dark">
                                        {{ $msg->sender_name }}
                                    </span>
                                @endif

                                <span>
                                    {{ \Iquesters\Foundation\Helpers\DateTimeHelper::displayConversational($msgTime) }}
                                </span>
                            </div>

                            @if($isFromMe)
                                <div class="d-flex justify-content-end gap-2 mb-1" style="font-size:10px;">
                                    <div class="star-rating" data-message-id="{{ $msg->id }}">
                                        @for ($i = 1; $i <= 5; $i++)
                                            <i class="fa-regular fa-star star text-warning"
                                            data-value="{{ $i }}"
                                            style="cursor:pointer;"></i>
                                        @endfor
                                    </div>
                                </div>
                            @endif

                            {{-- Message bubble --}}
                            <div class="{{ $msg->isText() ? 'p-2' : 'p-0' }} rounded-3 shadow-sm text-break
                                        {{ $isFromMe ? 'bg-primary-subtle text-primary-emphasis ms-auto' : 'bg-dark-subtle text-dark' }}"
                                style="word-wrap: break-word; overflow-wrap: break-word;font-size: 14px; width: fit-content; {{ $isFromMe ? 'margin-left: auto;' : '' }}">
                                
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
                                                <img src="{{ $mediaUrl }}" class="img-fluid w-100 rounded" />
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

                    {{-- ================= DEV MODE (ONLY FOR RECEIVED MESSAGE) ================= --}}
                    @if($isSuperAdmin && !$isFromMe)
                            <div class="d-flex justify-content-center mb-3 dev-mode-section d-none">

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

                                        <div id="devMode-{{ $index }}"
                                            class="accordion-collapse collapse dev-mode-collapse"
                                            data-integration-id="{{ $integrationUid }}"
                                            data-message-id="{{ $msg->message_id ?? $msg->id }}">
                                            <div class="accordion-body small bg-light rounded p-2">

                                                {{-- <div class="mb-2 text-start">
                                                    <span class="fw-semibold text-dark small">Inbound Message ID:</span>
                                                    <code>{{ $msg->message_id ?? $msg->id }}</code>
                                                </div>

                                                <div class="mb-2 text-start">
                                                    <span class="fw-semibold text-dark small">Integration UID:</span>
                                                    <code>{{ $integrationUid ?: 'N/A' }}</code>
                                                </div> --}}
                                                <div>
                                                    tool
                                                </div>

                                                 <div class="accordion w-75" id="devModeAccordion">
                                                    <div class="accordion-item border-0">

                                                        <h5 class="accordion-header">
                                                            <button class="accordion-button collapsed py-1 px-2 bg-dark-subtle text-dark"
                                                                    style="font-size:.75rem;"
                                                                    type="button"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#devMode">
                                                                <i class="fas fa-code me-1"></i> Api Request
                                                            </button>
                                                        </h5>
                                                    </div>
                                                 </div>
                                                {{-- API REQUEST --}}
                                                <div class="d-flex align-items-end gap-2 mb-1">
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




                                                <div class="mb-1 text-center">
                                                    <div class="fw-semibold text-primary small mb-1">Processing Steps</div>
                                                    <div class="diagnostics-steps" data-loaded="0">
                                                        <span class="text-muted fst-italic small">Expand to load steps...</span>
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

                @endforeach
            </div>

            {{-- Jump to Bottom Button (Gmail style) --}}
            <div id="jumpToBottomBtn" 
                class="position-absolute d-none"
                style="bottom: 80px; left: 50%; transform: translateX(-50%); z-index: 10;">
                <button class="btn btn-sm btn-primary d-flex align-items-center gap-2 py-2 px-3 border rounded-pill shadow-sm">
                    <span class="small">Jump to bottom</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>

            {{-- Input --}}
            <form id="sendMessageForm" class="d-flex align-items-center gap-2 p-2 border border-top">
                @csrf
                <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                <input type="hidden" name="to" id="messageTo" value="{{ $selectedContact }}">

                <!-- Plus -->
                <button type="button"
                        class="btn btn-sm rounded-pill bg-secondary-subtle text-primary flex-shrink-0 p-2">
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
                            class="btn btn-sm rounded-pill bg-secondary-subtle dropdown-toggle p-2"
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
                        class="btn btn-sm rounded-pill bg-secondary-subtle text-primary flex-shrink-0 p-2">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>

        </div>

        @endif

