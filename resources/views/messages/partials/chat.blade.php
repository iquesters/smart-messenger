<div id="chatView">
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

                {{-- MESSAGE INPUT --}}
                <div class="p-3 border-top bg-light">
                    <form id="sendMessageForm">
                        @csrf

                        <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
                        <input type="hidden" name="to" value="{{ $selectedContact }}">

                        <div class="input-group">
                            <input type="text"
                                   name="message"
                                   class="form-control"
                                   placeholder="Type a message..."
                                   required>
                            <button class="btn btn-primary">Send</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>