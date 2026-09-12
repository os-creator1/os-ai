<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §4.2/§6.1 — a version's state.
 *
 * At most one `draft` and at most one `published` version may exist per
 * workflow, and MySQL enforces both through the STORED generated guard columns
 * `draft_guard` / `published_guard` (migration 2). Any number of `superseded`
 * versions coexist, because each yields NULL in both guards.
 *
 * A version becomes immutable the moment it leaves `draft`: its compiled node
 * and edge rows are never updated, so an enrollment pinned to it keeps
 * executing exactly what it was published with.
 */
enum WorkflowVersionState: string
{
    /** Editable. Carries the definition document the builder autosaves. */
    case Draft = 'draft';

    /** Live. Immutable. New enrollments start here. */
    case Published = 'published';

    /** Replaced by a newer publish. Immutable, and retained while pinned. */
    case Superseded = 'superseded';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether this state's compiled graph must never be written again. Used by
     * the publisher and asserted by the immutability tests.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
