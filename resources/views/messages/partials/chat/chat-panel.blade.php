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
        <div class="flex-grow-1 d-flex flex-column position-relative overflow-hidden" id="chatPanel" style="min-height: 0; min-width: 0; flex: 1 1 0;">

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
                <div class="d-flex align-items-center justify-content-end flex-wrap gap-2" onclick="event.stopPropagation();">
                    @if(!empty($selectedContactHandoverState['active']))
                        <div class="d-flex align-items-center rounded-pill border border-warning-subtle bg-warning-subtle px-2 py-1">
                            <span class="badge rounded-pill text-bg-warning text-dark fw-semibold px-3 py-2">
                                Human Handover Active
                            </span>
                        </div>
                    @endif

                    @if($isSuperAdmin)
                        <div class="form-check form-switch mb-0 ms-3 d-inline-flex align-items-center">
                            <input
                                class="form-check-input mt-0"
                                type="checkbox"
                                role="switch"
                                id="devModeToggle"
                                data-is-super-admin="1"
                                style="cursor: pointer;">
                        </div>
                    @endif

                    @if(!empty($selectedContactHandoverState['active']) || (!empty($chatbotHumanHandoverEnabled) && !empty($selectedContactHandoverState['session_id'])))
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    title="Switch Agent"
                                    aria-label="Switch Agent">
                                <i class="fa-solid fa-rotate"></i>
                                <span class="fw-semibold">Switch Agent</span>
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end">
                                @if(!empty($selectedContactHandoverState['active']))
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item d-flex align-items-center gap-2"
                                            id="returnToBotDropdownBtn"
                                            data-session-id="{{ $selectedContactHandoverState['session_id'] ?? '' }}"
                                            data-contact-uid="{{ $selectedContactUid }}"
                                            data-chatbot-integration-uid="{{ $chatbotIntegrationUid }}"
                                            data-reason="agent_returned_control_to_bot">
                                            <i class="fa-solid fa-robot text-success"></i>
                                            Return To Bot
                                        </button>
                                    </li>
                                @elseif(!empty($chatbotHumanHandoverEnabled) && !empty($selectedContactHandoverState['session_id']))
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item d-flex align-items-center gap-2"
                                            id="activateHumanHandoverDropdownBtn"
                                            data-session-id="{{ $selectedContactHandoverState['session_id'] ?? '' }}"
                                            data-contact-uid="{{ $selectedContactUid }}"
                                            data-chatbot-integration-uid="{{ $chatbotIntegrationUid }}"
                                            data-reason="manual_human_handover">
                                            <i class="fa-solid fa-user-headset text-warning"></i>
                                            Human Handover
                                        </button>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endif
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

            <!-- Media Preview -->
            <div id="mediaPreview" class="d-none px-3 py-2 border-top bg-light d-flex align-items-center gap-2">
                <img id="mediaPreviewImg" src="" alt="preview" style="max-height:80px; max-width:80px; object-fit:cover; border-radius:8px;" class="d-none">
                <video id="mediaPreviewVideo" src="" style="max-height:80px; max-width:80px; border-radius:8px;" controls class="d-none"></video>
                <div class="flex-grow-1">
                    <div id="mediaPreviewName" class="small text-muted"></div>
                </div>
                <button type="button" id="removeMedia" class="btn btn-sm btn-outline-danger rounded-pill">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            {{-- Input --}}
            <form id="sendMessageForm" class="d-flex align-items-center gap-2 p-2 border border-top" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                <input type="hidden" name="to" id="messageTo" value="{{ $selectedContact }}">

                <!-- Plus -->
                <div class="position-relative flex-shrink-0">
                    <input type="file"
                        id="mediaFileInput"
                        name="media"
                        accept="image/png,image/jpeg,video/mp4,video/3gpp"
                        class="d-none">

                    <button type="button"
                            id="attachmentToggle"
                            class="btn btn-sm rounded-pill bg-secondary-subtle text-primary p-2">
                        <i class="fas fa-plus"></i>
                    </button>

                    <div id="attachmentDialog"
                        class="position-absolute bottom-100 start-0 mb-2 bg-white rounded-3 shadow border py-2 d-none"
                        style="min-width:180px; z-index:1050;">
                        <button type="button"
                                id="attachPhotosVideos"
                                class="btn btn-sm w-100 text-start px-3 py-2 d-flex align-items-center gap-2 rounded-0 border-0 bg-transparent">
                            <span class="rounded-circle d-flex align-items-center justify-content-center"
                                style="width:32px;height:32px;background:#e0f0ff;">
                                <i class="fas fa-image text-primary" style="font-size:14px;"></i>
                            </span>
                            <span class="fw-medium">Photos &amp; Videos</span>
                        </button>
                    </div>
                </div>

                <!-- Input -->
                <div class="flex-grow-1 position-relative">
                    <input type="text"
                        name="message"
                        class="form-control rounded-pill pe-5 bg-secondary-subtle py-2 px-3 border border-primary"
                        placeholder="Reply with message...">
            
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


