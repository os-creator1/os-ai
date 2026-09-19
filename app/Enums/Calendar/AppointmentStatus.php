<?php

namespace App\Enums\Calendar;

/**
 * Implementation Contract 15 §5.4 — an Appointment's current state.
 *
 * Blueprint §12's lifecycle is Scheduled -> reschedule/cancel are explicit
 * actions -> Completed or No-show. `Scheduled` is therefore the only
 * non-terminal case: §7.4 step 2 requires source state `scheduled` for every
 * lifecycle mutation, and `Cancelled`, `Completed` and `NoShow` are terminal
 * with no transition out of them.
 *
 * A reschedule is NOT a status change — it rewrites start_at/end_at (and
 * possibly staff_user_id) while the row stays `scheduled`, and increments
 * reschedule_count.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * §7.4: only `scheduled` may be mutated; the other three are terminal.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Scheduled;
    }
}
