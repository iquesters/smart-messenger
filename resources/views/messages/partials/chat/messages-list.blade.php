@php
    $lastDate = null;
@endphp

@foreach($messages as $msg)
    @php
        $isFromMe = $msg->from == $selectedNumber;
        $msgTime = \Carbon\Carbon::parse($msg->timestamp);
        $msgDate = $msgTime->toDateString();
        $handoverSummary = $msg->handoverSummary();
    @endphp

    @if($msgDate !== $lastDate)
        <div class="d-flex justify-content-center my-3 chat-date-separator" data-date="{{ $msgDate }}">
            <span class="badge bg-white text-dark fw-medium px-3 shadow-sm" style="font-size: 12px">
                {{ \Iquesters\Foundation\Helpers\DateTimeHelper::displaySmart($msgTime) }}
            </span>
        </div>
    @endif

    @php
        $lastDate = $msgDate;
    @endphp

    <div class="mb-2 pt-2 d-flex {{ $isFromMe ? 'justify-content-end' : 'justify-content-start' }} align-items-start chat-message-item"
         data-message-id="{{ $msg->id }}"
         data-message-date="{{ $msgDate }}">

        @if(!$isFromMe)
            <div class="me-2 flex-shrink-0">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                    style="width:24px;height:24px;font-size:10px;">
                    {{ substr($msg->from, -2) }}
                </div>
            </div>
        @endif

        @php
            $bubbleWidth = $msg->isText() ? '60%' : '30%';
        @endphp
        <div style="max-width: {{ $bubbleWidth }}; {{ $isFromMe ? 'margin-left: auto;' : '' }}" class="overflow-hidden">
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
                            <i class="fa-regular fa-star star text-warning" data-value="{{ $i }}" style="cursor:pointer;"></i>
                        @endfor
                    </div>
                </div>
            @endif

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
                        @if ($msg->message_type === 'image')
                            <div class="media-wrapper mb-1">
                                <img src="{{ $mediaUrl }}" class="img-fluid w-100 rounded" />
                            </div>
                        @elseif ($msg->message_type === 'video')
                            <video controls class="w-100 rounded mb-1">
                                <source src="{{ $mediaUrl }}">
                            </video>
                        @elseif ($msg->message_type === 'audio')
                            <audio controls class="w-100 mb-1">
                                <source src="{{ $mediaUrl }}">
                            </audio>
                        @else
                            <a href="{{ $mediaUrl }}" target="_blank" class="d-block text-decoration-none">
                                Download file
                            </a>
                        @endif
                    @endif

                    @if ($caption)
                        <div class="media-caption px-2 py-1 small">
                            {!! $msg->formattedCaption() !!}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if($isFromMe)
            <div class="ms-2 flex-shrink-0">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                    style="width:24px;height:24px;font-size:10px;">
                    {{ substr($selectedNumber, -2) }}
                </div>
            </div>
        @endif
    </div>

    @if(
        $handoverSummary && (
            !empty($handoverSummary['full_conversation_summary']) ||
            !empty($handoverSummary['handover_trigger_summary']) ||
            !empty($handoverSummary['agent_next_best_action']) ||
            !empty($handoverSummary['turns'])
        )
    )
        <div class="mb-3 d-flex justify-content-start handover-summary-wrapper">
            <div class="handover-summary-card w-100">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="fas fa-user-headset handover-summary-icon"></i>
                    <span class="fw-semibold">Human handover briefing</span>
                </div>

                @if(!empty($handoverSummary['full_conversation_summary']))
                    <div class="mb-2">
                        <div class="handover-summary-label">Conversation summary</div>
                        <p class="small mb-0">{{ $handoverSummary['full_conversation_summary'] }}</p>
                    </div>
                @endif

                @if(!empty($handoverSummary['handover_trigger_summary']))
                    <div class="mb-2">
                        <div class="handover-summary-label">Why handover happened</div>
                        <p class="small mb-0">{{ $handoverSummary['handover_trigger_summary'] }}</p>
                    </div>
                @endif

                @if(!empty($handoverSummary['agent_next_best_action']))
                    <div class="handover-next-best rounded-2 p-2 mb-2">
                        <div class="handover-summary-label">Agent next best action</div>
                        <p class="small fw-semibold mb-0">{{ $handoverSummary['agent_next_best_action'] }}</p>
                    </div>
                @endif

                @if(!empty($handoverSummary['turns']) && is_array($handoverSummary['turns']))
                    @php
                        $turnCount = count($handoverSummary['turns']);
                    @endphp

                    <div class="accordion accordion-flush handover-turns-accordion" id="handoverTurns-{{ $msg->id }}">
                        <div class="accordion-item border-0">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed handover-turns-toggle px-0 py-1 shadow-none"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#handoverTurnsCollapse-{{ $msg->id }}">
                                    <span class="small fw-semibold">Recent turn history ({{ $turnCount }})</span>
                                </button>
                            </h2>

                            <div id="handoverTurnsCollapse-{{ $msg->id }}" class="accordion-collapse collapse">
                                <div class="accordion-body px-0 pt-2 pb-0">
                                    @foreach($handoverSummary['turns'] as $turn)
                                        @php
                                            $userMessage = trim((string) ($turn['user_message'] ?? ''));
                                            $chatbotAnswer = trim((string) ($turn['chatbot_answer'] ?? ''));
                                        @endphp

                                        @if($userMessage !== '' || $chatbotAnswer !== '')
                                            <div class="handover-turn-item rounded-2 p-2 mb-2">
                                                @if($userMessage !== '')
                                                    <p class="small mb-1">
                                                        <span class="handover-turn-label">Customer:</span>
                                                        {{ $userMessage }}
                                                    </p>
                                                @endif

                                                @if($chatbotAnswer !== '')
                                                    <p class="small mb-0">
                                                        <span class="handover-turn-label">Bot:</span>
                                                        {{ $chatbotAnswer }}
                                                    </p>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if($isSuperAdmin && !$isFromMe)
        @include('smartmessenger::messages.partials.chat.dev-mode-item', [
            'index' => $msg->id,
            'msg' => $msg,
            'integrationUid' => $integrationUid,
        ])
    @endif
@endforeach
