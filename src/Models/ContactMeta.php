<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMeta extends Model
{
    use HasFactory;

    protected $table = 'contact_metas';

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Meta belongs to a contact
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'ref_parent');
    }
}