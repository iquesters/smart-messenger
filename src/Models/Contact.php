<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $table = 'contacts';

    protected $fillable = [
        'uid',
        'name',
        'identifier',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Contact has many meta entries
     */
    public function metas(): HasMany
    {
        return $this->hasMany(ContactMeta::class, 'ref_parent');
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
    public function setMetaValue(string $key, string $value)
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