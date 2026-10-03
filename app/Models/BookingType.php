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
        'meeting_instructions',
        'booking_window_days',
        'minimum_notice_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'slot_interval_minutes',
        'color',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'booking_window_days' => 'integer',
        'minimum_notice_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'slot_interval_minutes' => 'integer',
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

    // Effective scheduling settings. A freshly created model has not re-read the
    // column defaults yet, so each getter falls back to the legacy behaviour.
    public const DEFAULT_WINDOW_DAYS = 30;

    public const DEFAULT_SLOT_INTERVAL = 30;

    public const SLOT_INTERVALS = [15, 30, 45, 60];

    public function windowDays(): int
    {
        return (int) ($this->booking_window_days ?? self::DEFAULT_WINDOW_DAYS);
    }

    public function minimumNoticeMinutes(): int
    {
        return (int) ($this->minimum_notice_minutes ?? 0);
    }

    public function bufferBeforeMinutes(): int
    {
        return (int) ($this->buffer_before_minutes ?? 0);
    }

    public function bufferAfterMinutes(): int
    {
        return (int) ($this->buffer_after_minutes ?? 0);
    }

    public function slotIntervalMinutes(): int
    {
        return (int) ($this->slot_interval_minutes ?? self::DEFAULT_SLOT_INTERVAL);
    }

    /** Why this type's scheduling settings cannot produce slots, or null when they can. */
    public function schedulingProblem(): ?string
    {
        if ((int) $this->duration_minutes < 1) {
            return 'The duration must be at least one minute.';
        }
        if ($this->windowDays() < 1) {
            return 'The booking window must be at least one day.';
        }
        if (! in_array($this->slotIntervalMinutes(), self::SLOT_INTERVALS, true)) {
            return 'The start-time interval must be 15, 30, 45 or 60 minutes.';
        }

        return null;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
