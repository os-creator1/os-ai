<?php

namespace App\Events\Calendar;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Implementation Contract 15 §10 — a scheduled appointment was marked
 * no-show. Terminal, and mutually exclusive with completion (§7.4).
 */
class AppointmentNoShow implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $staffUserId,
        public readonly ?int $markedByUserId,
    ) {
    }
}
