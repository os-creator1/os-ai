<?php

namespace App\Events\Calendar;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 15 §10 — a scheduled appointment was completed.
 * Terminal, and mutually exclusive with no-show (§7.4).
 */
class AppointmentCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $staffUserId,
        public readonly ?int $completedByUserId,
    ) {
    }
}
