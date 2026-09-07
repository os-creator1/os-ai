<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Website Guided Generation contract §5.3 -- the append-only Profile
 * change ledger. Rows are only ever created, never updated or deleted by
 * application code (UPDATED_AT is disabled since the table has no
 * updated_at column at all, §16 Slice 1 migration 4). Written exclusively
 * by App\Library\Business\BusinessKnowledgeProfileManager.
 */
class BusinessKnowledgeProfileChange extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'field_key',
        'old_value',
        'new_value',
        'source',
        'actor_user_id',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
