<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Iquesters\Organisation\Traits\HasOrganisations;
use Iquesters\Foundation\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingProfile extends Model
{
    use HasFactory, HasOrganisations;

    protected $table = 'messaging_profiles';
    
    protected $fillable = [
        'uid',
        'provider_id',
        'name',
        'status',
        'is_default',
        'created_by',
        'updated_by'
    ];
    
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function metas(): HasMany
    {
        return $this->hasMany(MessagingProfileMeta::class);
    }

    public function getMeta(string $key)
    {
        $meta = $this->metas()->where('meta_key', $key)->first();
        return $meta ? $meta->meta_value : null;
    }

    public function setMetaValue(string $key, string $value)
    {
        return $this->metas()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value]
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(MasterData::class, 'provider_id');
    }
}