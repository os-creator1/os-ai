<?php

namespace App\Events\Calendar;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 15 §10 — an appointment moved in time, between
 * staff members, or both.
 *
 * BOTH STAFF IDS ARE ALWAYS POPULATED, deliberately (§10). §7.4 supports
 * "Reschedule (moving staff)", so a single staffUserId would be ambiguous
 * exactly when it matters most. On a same-staff reschedule the two are
 * equal; a consumer detects a staff move by comparing them, never by
 * inspecting which optional field was set.
 *
 * This event is the ONLY way anything learns the previous interval or
 * previous staff member — nothing persists them (§5.4) — and that still does
 * not make it an audit trail (§10).
 */
class AppointmentRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $previousStaffUserId,
        public readonly int $newStaffUserId,
        public readonly string $previousStartAt,
        public readonly string $previousEndAt,
        public readonly string $newStartAt,
        public readonly string $newEndAt,
        public readonly ?int $rescheduledByUserId,
    ) {
    }
}
