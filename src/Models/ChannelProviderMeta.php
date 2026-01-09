<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChannelProviderMeta extends Model
{
    use HasFactory;

    protected $table = 'channel_provider_metas';

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by'
    ];

    public function provider()
    {
        return $this->belongsTo(ChannelProvider::class, 'ref_parent');
    }
}