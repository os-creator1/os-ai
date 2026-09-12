<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Enums\Automation\Workflow\WorkflowTriggerType;

/**
 * Automations V2 §9 — what makes enrollments for one trigger type.
 *
 * Implemented by V2-C (contact created, date reached, manual) and V2-F (message
 * received). Declared here so V2-0's validator can refuse a trigger that has no
 * source, and so the lanes agree on one shape.
 *
 * CONTRACT FOR EVERY IMPLEMENTATION:
 *
 *   1. It runs AFTER COMMIT. A trigger that fires inside the transaction that
 *      created its subject can enroll against a row that never existed.
 *   2. It resolves the Business from the subject itself, never by inference. A
 *      subject with no explicit Business enrolls nobody — the fail-open
 *      default-to-user-1 mistake B4 §3.5 removed must not return here.
 *   3. It builds the occurrence key server-side, deterministically, so replaying
 *      the same real-world occurrence produces the same key and therefore the
 *      same refused duplicate.
 *   4. It bounds its own fan-out with `chunkById(WorkflowLimits::SWEEP_CHUNK_SIZE)`
 *      (Lane F §6.1) and never enrolls an unbounded set in one pass.
 *   5. Bulk-import-created contacts fire nothing (B4 §6.B), and that exclusion is
 *      preserved by every contact-shaped trigger.
 */
interface TriggerSource
{
    public function triggerType(): WorkflowTriggerType;

    /**
     * Whether this source is wired and able to enroll right now. A trigger whose
     * producer has not shipped yet reports false, and the validator refuses to
     * publish a workflow that would wait forever.
     */
    public function isAvailable(): bool;
}
