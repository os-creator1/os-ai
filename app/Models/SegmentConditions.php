<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SegmentConditions extends Model
{
    use HasUid;

    protected $fillable = [
        'field_id', 'operator', 'value',
    ];

    /**
     * Retrieves the related ContactGroupFields model instance.
     *
     * @return BelongsTo The related ContactGroupFields model instance.
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactGroupFields');
    }
}
