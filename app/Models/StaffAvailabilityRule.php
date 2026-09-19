<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §5.2 — one recurring weekly window for one
 * staff member at one Location.
 *
 * `start_time`/`end_time` are LOCAL time-of-day in the Location's Business's
 * timezone (§3.3), deliberately NOT cast to datetime: casting them would
 * invent a date and an offset the column does not carry, which is exactly
 * the DST corruption §5.2 forbids. They stay plain `H:i:s` strings; the
 * timezone-aware math happens at read time against `business.timezone`.
 *
 * Multiple rows per (staff, Location, day) are allowed — that is a split
 * shift, not a duplicate.
 */
class StaffAvailabilityRule extends Model
{
    protected $fillable = [
        'business_location_id',
        'staff_user_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
