<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $table = 'chat_sessions';

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'contact_uid',
        'integration_id',
        'context_json',
        'created_at',
        'last_active_at',
        'expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_active_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
