<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MessagingProfileMeta extends Model
{
    use HasFactory;

    protected $table = 'messaging_profile_metas';

    protected $fillable = [
        'Messaging_profile_id',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by'
    ];
}