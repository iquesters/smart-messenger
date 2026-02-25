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
                id="messagesContainer"
                data-profile-id="{{ $profile?->id }}"
                data-selected-contact="{{ $selectedContact }}"
                data-selected-number="{{ $selectedNumber }}"
                data-oldest-id="{{ $oldestMessageId ?? '' }}"
                data-has-more="{{ !empty($hasMoreMessages) ? '1' : '0' }}">
                @include('smartmessenger::messages.partials.chat.messages-list', [
                    'messages' => $messages,
                    'selectedNumber' => $selectedNumber,
                    'isSuperAdmin' => $isSuperAdmin,
                    'integrationUid' => $integrationUid,
                ])
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


