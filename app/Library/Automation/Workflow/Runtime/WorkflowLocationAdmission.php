<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\WorkflowLocationScope;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Models\AutomationWorkflowVersion;
use App\Models\BusinessLocation;
use Illuminate\Support\Facades\DB;

/**
 * Automations V1 completion — Location run-scope foundation, lane contract
 * §10: THE ONE `publishedVersionAllowsLocation(version, businessLocation)`
 * authority. Every place that decides whether a Business Location may cause
 * a NEW enrollment on a given pinned version calls this — never a second,
 * subtly different re-implementation.
 *
 * Deliberately folds every input check into one predicate rather than
 * splitting "does this Location exist/belong/stay active" from "does the
 * scope admit it" across caller and callee: the lane contract's own
 * admission table names all of these together (§10), and a caller-side
 * split is exactly how "eight subtly different versions" would happen.
 *
 * Table, exactly (§10):
 *
 *   All      + active same-Business Location -> yes
 *   All      + null                          -> no
 *   All      + foreign Business               -> no
 *   All      + archived                        -> no NEW enrollment
 *   Selected + selected same-Business active   -> yes
 *   Selected + unselected Location              -> no
 *   One      + exact configured active Location -> yes
 *   One      + any other Location                -> no
 *   invalid/malformed persisted scope             -> fail closed, never "all"
 */
class WorkflowLocationAdmission
{
    /**
     * @param AutomationWorkflowVersion $version the PINNED (published or
     *        superseded) version an enrollment is being admitted against —
     *        never a draft, whose scope is not yet denormalised here at all
     * @param BusinessLocation|null $location the run's candidate Location,
     *        already re-read from its own repository/query by the caller —
     *        this method re-derives nothing about it beyond its own columns
     */
    public function admits(AutomationWorkflowVersion $version, ?BusinessLocation $location): bool
    {
        if ($location === null) {
            return false;
        }

        if ($location->business_id === null || (int) $location->business_id !== (int) $version->business_id) {
            return false;
        }

        if ($location->lifecycle_state !== BusinessLocationLifecycleState::Active) {
            return false;
        }

        return match ($version->location_scope) {
            WorkflowLocationScope::All => true,
            WorkflowLocationScope::Selected, WorkflowLocationScope::One => $this->isExplicitlySelected($version, $location),
            // NULL (a version this lane's own backfill somehow missed) or
            // any other unrecognised value: fail closed, never "all".
            default => false,
        };
    }

    private function isExplicitlySelected(AutomationWorkflowVersion $version, BusinessLocation $location): bool
    {
        return DB::table('automation_workflow_version_locations')
            ->where('version_id', $version->getKey())
            ->where('business_location_id', $location->getKey())
            ->exists();
    }
}
