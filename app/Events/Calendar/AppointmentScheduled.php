<?php

namespace App\Events\Calendar;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 15 §10 — a new appointment was committed.
 *
 * Numeric ids and timestamps only, no PII. NOT an audit record: §10 is
 * explicit that these are a notification mechanism for Automations, and this
 * slice persists no appointment history (§5.4).
 *
 * ShouldDispatchAfterCommit, so a refused or rolled-back booking emits
 * nothing (§7.4 step 4).
 */
class AppointmentScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $businessLocationId,
        public readonly int $bookingTypeId,
        public readonly int $staffUserId,
        public readonly int $contactId,
        public readonly ?int $crmOpportunityId,
        public readonly string $startAt,
        public readonly string $endAt,
        public readonly ?int $createdByUserId,
    ) {
    }
}
