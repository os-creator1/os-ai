<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §7.1 — the state of one contact's journey.
 *
 * THE LOAD-BEARING INVARIANT: `Active` means the cursor node is executable
 * NOW; `Waiting` means it is not until `resume_at`. There is no third
 * non-terminal state. Resume depends on exactly this (§6.3) — it re-dispatches
 * every `Active` enrollment of the resumed workflow without having to inspect
 * any node, and it never touches a `Waiting` one.
 */
enum EnrollmentStatus: string
{
    /** Cursor node is executable now. */
    case Active = 'active';

    /** Parked on a wait node until `resume_at`. */
    case Waiting = 'waiting';

    /** Reached the end of its path. */
    case Completed = 'completed';

    /** A step failed under the `halt` failure policy. */
    case Failed = 'failed';

    /**
     * Left early for a legitimate reason: the contact unsubscribed or was
     * deleted, the Business went inactive, or the lifetime cap was reached.
     */
    case Exited = 'exited';

    /** Stopped by an operator action: workflow archived, or stop-all. */
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Active, self::Waiting], true);
    }

    /**
     * The two states that occupy `active_contact_guard`, and therefore the two
     * that block a second concurrent enrollment of the same contact.
     */
    public static function occupyingStatuses(): array
    {
        return [self::Active, self::Waiting];
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'In progress',
            self::Waiting => 'Waiting',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Exited => 'Exited',
            self::Cancelled => 'Cancelled',
        };
    }
}
