<?php

namespace App\Library\Automation\Workflow;

/**
 * Automations V2 §8.4 — every structural limit, in one place.
 *
 * All of these are owner-approved (decision D5, with
 * STALE_ACTIVE_RECOVERY_MINUTES approved in Correction Round 2). They live
 * together so that changing one is a single reviewed line rather than a hunt
 * through the engine, and so a reader can see the whole safety envelope at once.
 *
 * Nothing here is a preference: each bounds a way a workflow could otherwise
 * consume unbounded time, money or storage.
 */
final class WorkflowLimits
{
    /** Compile refuses a version with more steps than this. */
    public const MAX_NODES_PER_VERSION = 50;

    /** How deeply If/Else steps may nest. The root trigger is depth 0. */
    public const MAX_BRANCH_DEPTH = 5;

    /** Conditions in one If/Else, combined by a single all/any. */
    public const MAX_CONDITIONS_PER_BRANCH = 5;

    /** A wait must be at least this long: the scheduler's own resolution. */
    public const MIN_WAIT_MINUTES = 1;

    /** A single wait may not exceed one year. */
    public const MAX_WAIT_DAYS = 365;

    /** An enrollment older than this is exited as `lifetime_exceeded`. */
    public const MAX_ENROLLMENT_LIFETIME_DAYS = 400;

    /**
     * Steps one advance job may run before re-dispatching itself. Keeps a single
     * job well inside the worker's `--timeout=120`.
     */
    public const MAX_STEPS_PER_ADVANCE_JOB = 10;

    /** Definition document ceiling, in bytes. */
    public const MAX_DEFINITION_BYTES = 262144;

    /** Cross-workflow cascade bound (Lane F §6.1). */
    public const MAX_CAUSATION_DEPTH = 3;

    /** Fan-out fuse: new enrollments per workflow per rolling hour. */
    public const MAX_ENROLLMENTS_PER_WORKFLOW_PER_HOUR = 1000;

    /** Contacts one manual enrollment request may enroll. */
    public const MAX_MANUAL_ENROLLMENTS_PER_REQUEST = 500;

    /** Published workflows one Business may hold. */
    public const MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS = 200;

    /** Enrollments one sweep run may process before continuing itself. */
    public const SWEEP_ENROLLMENTS_PER_RUN = 2000;

    /** The house batch size for every sweep (`chunkById`, Lane F §6.1). */
    public const SWEEP_CHUNK_SIZE = 50;

    /** Draft autosaves per user per minute. */
    public const MAX_AUTOSAVES_PER_MINUTE = 30;

    /**
     * How long an `active` enrollment of a published workflow may sit without
     * advancing before the recovery sweep treats it as a lost or interrupted
     * job (§7.4).
     *
     * THIS IS NOT HOW RESUME WORKS. Resume re-dispatches held enrollments
     * immediately, after its own transaction commits (§6.3). This threshold only
     * catches advance jobs that were genuinely lost — a dropped queue, a killed
     * deploy — and is comfortably longer than one advance job can run plus one
     * scheduler cycle, so a merely slow job is never mistaken for a lost one.
     */
    public const STALE_ACTIVE_RECOVERY_MINUTES = 15;

    /**
     * Per contact, per workflow, for message-received enrollments (D6). Named so
     * a later product setting can replace it without touching the engine.
     */
    public const MESSAGE_RECEIVED_COOLDOWN_HOURS = 24;

    private function __construct()
    {
        // Not instantiable: this is a constant table, not a service.
    }
}
