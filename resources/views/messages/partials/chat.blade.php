<div id="chatView">
    <div class="row" style="height: 600px;">

        {{-- LEFT SIDEBAR --}}
        <div class="col-md-4 border-end d-flex flex-column"
             style="height: 100%; background: #f8f9fa;">

            {{-- Header --}}
            <div class="p-2 border-bottom bg-white">

                {{-- Search box --}}
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white py-2">
                        <i class="fas fa-fw fa-search"></i>
                    </span>

                    <input type="text"
                        id="chatSearch"
                        class="form-control border-start-0 py-2"
                        placeholder="Search">
                </div>
            </div>

            {{-- Contacts --}}
            <div class="flex-grow-1 overflow-auto">
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
                                 style="max-width:60%;background:{{ $isFromMe ? '#dcf8c6' : '#fff' }};">
                                <div>{{ $msg->content }}</div>
                                <div class="text-end text-muted" style="font-size:10px;">
                                    {{ \Carbon\Carbon::parse($msg->timestamp)->format('H:i') }}
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
                    @endforeach
                </div>

                {{-- Input --}}
                <div class="p-3 border-top" style="background:#e5ddd5;">
                    <form id="sendMessageForm">
                        @csrf
                        <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                        <input type="hidden" name="to" value="{{ $selectedContact }}">

                        <div class="input-group">
                            <input type="text" name="message" class="form-control"
                                   placeholder="Reply with message..." required>
                            <button class="btn bg-white text-primary">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>