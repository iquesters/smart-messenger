<?php

namespace Iquesters\SmartMessenger\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Iquesters\SmartMessenger\Models\Message;

class MessageReceivedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    
    // ✅ THESE TWO LINES ARE CRITICAL
    public $connection = 'database';
    public $queue = 'broadcasts';

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel("messaging.channel.{$this->message->channel_id}.user.{$this->message->from}"),
            new Channel("messaging.channel.{$this->message->channel_id}.user.{$this->message->to}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
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
            'type' => 'received',
            'is_from_me' => false, // ✅ Important: identifies received messages
            'human_time' => $this->message->timestamp->format('H:i'),
        ];
    }
}