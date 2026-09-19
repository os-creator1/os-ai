<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §5.3 — a staff member's availability exception.
 *
 * DELIBERATELY USER-GLOBAL: there is no Location relation here because the
 * table has no business_location_id column, and that is the design. Time off
 * removes the staff member from EVERY Location they are granted, not one —
 * §14's acceptance wording states this exception outright rather than
 * implying a Location column that does not exist.
 *
 * start_at/end_at are UTC.
 */
class StaffTimeOff extends Model
{
    protected $table = 'staff_time_off';

    protected $fillable = [
        'staff_user_id',
        'start_at',
        'end_at',
        'reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
