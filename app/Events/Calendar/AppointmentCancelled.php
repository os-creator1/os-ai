<?php

namespace App\Events\Calendar;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 15 §10 — a scheduled appointment was cancelled.
 * Terminal: §7.4 allows no transition out of it, so this fires at most once
 * per appointment.
 */
class AppointmentCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $staffUserId,
        public readonly ?int $cancelledByUserId,
        public readonly ?string $reason,
    ) {
    }
}
