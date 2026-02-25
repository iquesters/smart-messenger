@php
    $lastDate = null;
@endphp

@foreach($messages as $msg)
    @php
        $isFromMe = $msg->from == $selectedNumber;
        $msgTime = \Carbon\Carbon::parse($msg->timestamp);
        $msgDate = $msgTime->toDateString();
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

    @if($isSuperAdmin && !$isFromMe)
        @include('smartmessenger::messages.partials.chat.dev-mode-item', [
            'index' => $msg->id,
            'msg' => $msg,
            'integrationUid' => $integrationUid,
        ])
    @endif
@endforeach
