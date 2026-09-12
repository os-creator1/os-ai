<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §4.1/§6.3 — a workflow's lifecycle state.
 *
 * This is the only behaviour-affecting field that lives on the workflow row
 * rather than on a version, because it is lifecycle rather than definition:
 * pausing must take effect for the live version immediately, which is the
 * opposite of how a definition edit behaves.
 */
enum WorkflowStatus: string
{
    /** Never published. Has a draft version and no published version. */
    case Draft = 'draft';

    /** A published version exists and accepts new enrollments. */
    case Published = 'published';

    /**
     * New enrollments are blocked and in-flight enrollments are HELD at their
     * cursor — no step executes. Resume re-dispatches them explicitly (§6.3).
     */
    case Paused = 'paused';

    /** Permanently stopped. In-flight enrollments are cancelled. */
    case Archived = 'archived';

    /** Whether trigger ingestion may create new enrollments. */
    public function acceptsNewEnrollments(): bool
    {
        return $this === self::Published;
    }

    /** Whether the advancer may execute a step for this workflow. */
    public function permitsExecution(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }
}
