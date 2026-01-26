<?php

namespace Iquesters\SmartMessenger\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Iquesters\SmartMessenger\Models\Message;

class MessageSentEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    
    public $connection = 'database';
    public $queue = 'broadcasts';

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        // Sanitize phone numbers by removing '+' and other special characters
        $sanitizedFrom = $this->sanitizeChannelIdentifier($this->message->from);
        $sanitizedTo = $this->sanitizeChannelIdentifier($this->message->to);
        
        return [
            new Channel("messaging.channel.{$this->message->channel_id}.user.{$sanitizedFrom}"),
            new Channel("messaging.channel.{$this->message->channel_id}.user.{$sanitizedTo}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'channel_id' => $this->message->channel_id,
            'message_id' => $this->message->message_id,
            'from' => $this->message->from,
            'to' => $this->message->to,
            'content' => $this->message->content,
            'message_type' => $this->message->message_type,
            'timestamp' => $this->message->timestamp->toISOString(),
            'status' => $this->message->status,
            'created_by' => $this->message->created_by,
            'type' => 'sent',
            'is_from_me' => true,
            'human_time' => $this->message->timestamp->format('H:i'),
        ];
    }

    /**
     * Sanitize channel identifier by removing invalid characters
     * Reverb/Pusher channel names can only contain: a-z, A-Z, 0-9, =, @, ,, ., ;, -, _
     */
    private function sanitizeChannelIdentifier(?string $identifier): string
    {
        if (!$identifier) {
            return 'unknown';
        }

        // Remove '+' and other invalid characters, keep only alphanumeric, dash, underscore, dot
        return preg_replace('/[^a-zA-Z0-9\-_.]/', '', $identifier);
    }
}