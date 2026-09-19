<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §5.1.1 — the durable round-robin rotation
 * cursor, one row per Booking Type.
 *
 * It holds exactly one fact: which staff member received the last
 * SUCCESSFULLY COMMITTED round-robin assignment. §7.3 advances it only on
 * commit, under tier 1 of §7.4's single canonical lock order; a null cursor
 * is a defined state, not an error (rotation starts at the lowest eligible
 * id). Sub-slice C owns that logic — this model is casts/relations only.
 */
class BookingTypeRoundRobinState extends Model
{
    protected $table = 'booking_type_round_robin_state';

    protected $fillable = [
        'booking_type_id',
        'last_assigned_staff_user_id',
        'last_assigned_at',
    ];

    protected $casts = [
        'last_assigned_at' => 'datetime',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function lastAssignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_staff_user_id');
    }
}
