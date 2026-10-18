<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed Contact value for one Custom Field definition. Written only by
 * `CustomFieldValueService`.
 */
class CustomFieldValue extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value_json' => 'array',
        'value_bool' => 'boolean',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }
}
