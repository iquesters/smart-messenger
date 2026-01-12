<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'message_id',
        'from',
        'to',
        'message_type',
        'content',
        'timestamp',
        'status',
        'raw_payload',
        'raw_response',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'timestamp'     => 'datetime',
        'raw_payload'   => 'array',
        'raw_response'  => 'array',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}