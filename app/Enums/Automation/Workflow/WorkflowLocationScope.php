<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V1 completion — Location run-scope foundation (Blueprint §5,
 * §13; Addendum §5). Which Locations of the workflow's Business may cause a
 * NEW enrollment. It never widens a run to more than one Location — every
 * individual enrollment still binds to exactly one Location for its entire
 * execution (`automation_enrollments.business_location_id`, pinned and
 * immutable). This is the definition-scope axis only: "which Locations are
 * allowed to cause a run", not "how many Locations one run touches".
 *
 * Like `EnrollmentPolicy`/`FailurePolicy` (§7.5/§7.6), this is part of the
 * VERSIONED definition: denormalised onto `automation_workflow_versions` at
 * publish and pinned there, so an enrollment always reads the scope of the
 * version it is actually being admitted against, and a later republish never
 * changes what an in-flight run — or an already-published version's own
 * admission rule — meant.
 */
enum WorkflowLocationScope: string
{
    case All = 'all';
    case Selected = 'selected';
    case One = 'one';

    /** Whether a persisted `location_ids` list for this scope must be non-empty. */
    public function requiresExplicitLocations(): bool
    {
        return $this !== self::All;
    }

    /** `One` admits exactly one configured Location; `Selected` admits one or more. */
    public function allowsMultipleLocations(): bool
    {
        return $this === self::Selected;
    }

    public static function default(): self
    {
        return self::All;
    }
}
