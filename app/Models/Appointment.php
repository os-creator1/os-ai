<?php

namespace App\Models;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 15 §5.4 — the canonical Appointment record.
 *
 * CURRENT STATE ONLY. V1 ships no `appointment_transitions` table by
 * decision (§5.4, §10, §15): the row preserves the current booking, the
 * outcome and `reschedule_count`, and the PREVIOUS interval and previous
 * staff member of each reschedule are not durably queryable — the
 * AppointmentRescheduled event carrying them is transient, and RFC-002 §41
 * is explicit that domain events are a notification mechanism, not an audit
 * log. Mutation authority (lock order, allowed source states, events)
 * belongs to Sub-slice C; this model is casts/relations only.
 */
class Appointment extends Model
{
    use HasUid;

    protected $fillable = [
        'business_location_id',
        'booking_type_id',
        'staff_user_id',
        'contact_id',
        'crm_opportunity_id',
        'created_by_user_id',
        'status',
        'start_at',
        'end_at',
        'reschedule_count',
        'resolved_at',
        'resolved_by_user_id',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => AppointmentStatus::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'reschedule_count' => 'integer',
        'resolved_at' => 'datetime',
    ];

    /**
     * HasUid's default is uniqid(); this column is a real uuid (§5.1's rule
     * for every Schema A model that uses the trait).
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    /**
     * §5.7 — the ONLY coupling point to CRM, and it points one way.
     * Computing or writing a "Booked" badge on CrmOpportunity is explicitly
     * not this slice's job: Calendar publishes events, CRM may subscribe.
     */
    public function crmOpportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'crm_opportunity_id');
    }

    /**
     * Null means the appointment was created via public self-booking, which
     * has no authenticated actor (§5.4).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isScheduled(): bool
    {
        return $this->status === AppointmentStatus::Scheduled;
    }
}
