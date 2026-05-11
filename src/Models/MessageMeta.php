<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MessageMeta extends Model
{
    use HasFactory;

    protected $table = 'message_metas';

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by'
    ];

    public function message()
    {
        return $this->belongsTo(Message::class, 'ref_parent');
    }
}