<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Iquesters\Organisation\Traits\HasOrganisations;
use App\Models\User;

class Workflow extends Model
{
    use HasFactory, HasOrganisations;

    protected $fillable = [
        'uid',
        'name',
        'status',
        'is_default',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function metas()
    {
        return $this->hasMany(WorkflowMeta::class, 'ref_parent');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Meta Helpers (Same Pattern as Channel)
    |--------------------------------------------------------------------------
    */

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