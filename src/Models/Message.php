<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Message has many meta entries
     */
    public function metas(): HasMany
    {
        return $this->hasMany(MessageMeta::class, 'ref_parent');
    }

    /**
     * Get meta value by key
     */
    public function getMeta(string $key): ?string
    {
        return $this->metas()
            ->where('meta_key', $key)
            ->value('meta_value');
    }

    /**
     * Set or update meta value
     */
    public function setMeta(string $key, string $value)
    {
        return $this->metas()->updateOrCreate(
            [
                'ref_parent' => $this->id,
                'meta_key'   => $key,
            ],
            [
                'meta_value' => $value,
            ]
        );
    }
}