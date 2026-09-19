<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Implementation Contract 15 §5.1 — what can be booked, for how long, at
 * exactly one Location. Casts/relations only in this sub-slice; CRUD
 * authority belongs to Sub-slice B.
 */
class BookingType extends Model
{
    use HasUid;

    protected $fillable = [
        'business_location_id',
        'name',
        'description',
        'duration_minutes',
        'color',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * HasUid's default generateUid() is `uniqid()`, which is neither a valid
     * UUID nor safe for anything security-adjacent despite the column's
     * `uuid` type — the exact defect app/Models/Website.php:18-20 names
     * about Business.uid. Overridden exactly as the 20 existing models do.
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    /**
     * Contract §5.1 — `public_booking_uuid` is a SEPARATE identifier from
     * `uid` and needs its own creating-time generation, independent of
     * HasUid's own creating() hook (which only ever touches `uid`).
     * Model::bootIfNotBooted() calls static::boot() then static::booted() as
     * two independent steps, so both fire. This mirrors
     * app/Models/Website.php:60-67, whose `public_id` exists for the
     * identical reason: the public surface must never be addressed by a
     * guessable uniqid()-shaped value (routes/public.php:210-215).
     *
     * Not fillable: the public address is generated, never supplied by a
     * caller or a request.
     */
    protected static function booted(): void
    {
        static::creating(function (BookingType $bookingType): void {
            if (empty($bookingType->public_booking_uuid)) {
                $bookingType->public_booking_uuid = (string) Str::uuid();
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The round-robin pool (§7.3). CONFIGURATION INTENT ONLY — a row here is
     * never proof that the staff member may currently be scheduled at this
     * Location. §6 re-derives that through LocationAccessGuard at both
     * configuration time and booking time, so a stale pivot row never
     * restores access.
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'booking_type_staff', 'booking_type_id', 'staff_user_id')
            ->withTimestamps();
    }

    public function roundRobinState(): HasOne
    {
        return $this->hasOne(BookingTypeRoundRobinState::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
