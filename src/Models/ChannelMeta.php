<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChannelMeta extends Model
{
    use HasFactory;

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by'
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}