<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §7.2 — the per-staff serialization row.
 *
 * It holds no data. Its only purpose is to be locked with
 * SELECT ... FOR UPDATE (§7.1 tier 2) so two overlapping booking attempts
 * for one staff member cannot both succeed. `staff_user_id` IS the primary
 * key, which is what makes §7.2's `insertOrIgnore` ensure step genuinely
 * idempotent.
 *
 * Sub-slice C owns the ensure-then-lock sequence; this model exists so that
 * sub-slice needs no schema change of its own.
 */
class StaffBookingLock extends Model
{
    protected $table = 'staff_booking_locks';

    protected $primaryKey = 'staff_user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'staff_user_id',
    ];

    protected $casts = [
        'staff_user_id' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
