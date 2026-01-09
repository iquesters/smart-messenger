<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChannelProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid',
        'name',
        'small_name',
        'nature',
        'status',
        'created_by',
        'updated_by'
    ];

    public function metas()
    {
        return $this->hasMany(ChannelProviderMeta::class, 'ref_parent');
    }

    public function channels()
    {
        return $this->hasMany(Channel::class);
    }
    
    public function getMeta(string $key)
    {
        return optional(
            $this->metas()->where('meta_key', $key)->first()
        )->meta_value;
    }
}