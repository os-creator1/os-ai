<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §5.8.3 — the Location-local Contact identity
 * serialization row.
 *
 * It exists because `contacts` cannot enforce Location-local identity
 * itself: it carries no unique index of any kind and one cannot be added
 * retroactively (§5.8.1), so `insertOrIgnore` against it would guarantee
 * nothing. This table's own unique key on
 * (business_location_id, normalized_phone) is what makes §5.8.4's
 * ensure-then-lock sequence idempotent.
 *
 * It carries NO contact_id and no other data, deliberately: a second
 * pointer to the resolved Contact would be a second source of truth that
 * can drift from `contacts`.
 *
 * `normalized_phone` is the form `contacts.phone` ACTUALLY stores (§5.8.2),
 * never E.164 — a different normalization would never match existing rows.
 * Sub-slice E owns the resolution algorithm; this model is relations only.
 */
class BookingContactIdentityLock extends Model
{
    protected $fillable = [
        'business_location_id',
        'normalized_phone',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }
}
