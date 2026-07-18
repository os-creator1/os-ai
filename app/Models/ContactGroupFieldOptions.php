<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactGroupFieldOptions extends Model
{
    use HasUid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'label', 'value', 'field_id',
    ];

    /**
     * Retrieve the associated ContactGroupFields model.
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactGroupFields');
    }
}
