<style>
    .chat-box {
        height: 500px;
        overflow-y: auto;
        background: #f8f8f8;
        border-radius: 10px;
        padding: 15px;
    }
    .chat-bubble {
        max-width: 60%;
        padding: 10px 14px;
        border-radius: 12px;
        margin-bottom: 10px;
    }
    .chat-left {
        background: #e9ecef;
        align-self: flex-start;
    }
    .chat-right {
        background: #cfe2ff;
        align-self: flex-end;
    }
</style>

{{-- Chat View --}}
<div id="chatView" class="d-none border p-3 rounded" style="height: 500px; overflow-y: auto;">
    @if(!$chatNumber)
        <div class="text-muted">Select a chat on the left to view messages.</div>
    @else
        @foreach($messages as $msg)
            <div class="mb-2 d-flex {{ $msg->from == $chatNumber ? 'justify-content-start' : 'justify-content-end' }}">
                <div class="p-2 rounded" 
                     style="max-width: 60%; 
                            background: {{ $msg->from == $chatNumber ? '#f1f1f1' : '#d1e7ff' }}">
                    <strong>{{ $msg->from == $chatNumber ? 'Them' : 'You' }}</strong><br>
                    {{ $msg->content }}
                    <div class="small text-muted mt-1">{{ $msg->timestamp }}</div>
                </div>
            </div>
        @endforeach
    @endif
</div>
