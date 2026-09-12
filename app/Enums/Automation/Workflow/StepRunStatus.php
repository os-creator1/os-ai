<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §7.2 — the state of one executed step.
 *
 * A step run is created ALREADY `Started`, inside the same short transaction
 * that verified the enrollment's cursor under a row lock, and before any
 * provider call. There is no separate "reserved" state: the enrollment row lock
 * plus `UNIQUE(enrollment_id, node_id)` already serialize duplicate job
 * delivery, so a second worker loses the insert rather than needing a second
 * claim (which is what B4 §5.4 had to add for its flat ledger).
 */
enum StepRunStatus: string
{
    /** Claimed and committed to execute. */
    case Started = 'started';

    /** A wait node, between arrival and wake-up. */
    case Waiting = 'waiting';

    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Eligibility changed before the action ran; never retried. */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Started, self::Waiting], true);
    }

    /**
     * Whether this status represents a step the recovery sweep should consider
     * interrupted if its enrollment has gone stale (§7.4).
     */
    public function isInterruptible(): bool
    {
        return $this === self::Started;
    }
}
