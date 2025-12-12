<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';

    protected $fillable = [
        'messaging_profile_id',
        'message_id',
        'from',
        'to',
        'message_type',
        'content',
        'timestamp',
        'status',
        'raw_payload',
        'raw_response',
    ];

    protected $casts = [
        'timestamp'     => 'datetime',
        'raw_payload'   => 'array',
        'raw_response'  => 'array',
    ];

    /**
     * Relationship: Message belongs to MessagingProfile
     */
    public function profile()
    {
        return $this->belongsTo(MessagingProfile::class, 'messaging_profile_id');
    }
}