<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Website Guided Generation contract §5.1/§5.2. One row per
 * (business_id, field_key) -- upserted exclusively by
 * App\Library\Business\BusinessKnowledgeProfileManager. Never tracks
 * `hours` (business_locations owns hours provenance directly, §5.5) or
 * the derived `reviews_source` (§4.2).
 */
class BusinessKnowledgeProfileFieldState extends Model
{
    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_CUSTOMER_CONFIRMED = 'customer_confirmed';

    protected $fillable = [
        'business_id',
        'field_key',
        'source',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
