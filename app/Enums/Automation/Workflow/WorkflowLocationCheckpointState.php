<?php

namespace App\Enums\Automation\Workflow;

/**
 * Location run-scope foundation — runtime checkpoint correction (independent
 * review, pre-merge finding #2): whether an already-pinned enrollment's own
 * Location still permits its next step to execute, checked immediately
 * before every step — never at enrollment time only.
 *
 * This is a DIFFERENT question from `WorkflowLocationAdmission::admits()`,
 * which decides whether a Location may cause a NEW enrollment against a
 * pinned VERSION's scope — a one-time gate, settled forever the moment the
 * enrollment is created. What can change AFTER that is only whether the
 * pinned Location itself is still there and still active; the version's
 * scope admission is not re-litigated on every step.
 */
enum WorkflowLocationCheckpointState: string
{
    /** The pinned Location exists, belongs to this Business, and is Active. Proceed. */
    case Active = 'active';

    /**
     * The pinned Location exists and belongs to this Business, but is
     * currently Archived. Temporary and reversible
     * (`BusinessLocationManager::reactivateLocation()` exists) — the same
     * shape as a currently-denied entitlement (§7.3): HOLD, do not exit.
     */
    case Archived = 'archived';

    /**
     * No Location can be proven for this run at all: `business_location_id`
     * is NULL (a historical row this lane's own backfill could not resolve),
     * or the id no longer resolves to a real, same-Business row. Nothing
     * will ever retroactively prove one — permanent: EXIT, close the journey
     * honestly rather than holding it forever unresumable.
     */
    case Unresolved = 'unresolved';
}
