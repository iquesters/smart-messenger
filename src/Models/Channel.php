<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Iquesters\Organisation\Traits\HasOrganisations;
use App\Models\User;

class Channel extends Model
{
    use HasFactory, HasOrganisations;

    protected $fillable = [
        'uid',
        'user_id',
        'channel_provider_id',
        'name',
        'status',
        'is_default',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function metas()
    {
        return $this->hasMany(ChannelMeta::class, 'ref_parent');
    }

    public function provider()
    {
        return $this->belongsTo(ChannelProvider::class, 'channel_provider_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
    
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getMeta(string $key)
    {
        return optional(
            $this->metas()->where('meta_key', $key)->first()
        )->meta_value;
    }

    public function setMeta(string $key, $value)
    {
        return $this->metas()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value]
        );
    }
}