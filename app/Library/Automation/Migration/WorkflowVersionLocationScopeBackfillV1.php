<?php

namespace App\Library\Automation\Migration;

use App\Enums\Automation\Workflow\WorkflowLocationScope;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use Illuminate\Support\Facades\DB;

/**
 * Automations V1 completion — Location run-scope foundation, §5C/§7 of the
 * lane's own contract: every existing PUBLISHED or SUPERSEDED
 * `automation_workflow_versions` row effectively listened Business-wide
 * before this feature existed, so it becomes `WorkflowLocationScope::All`
 * — never silently narrowed to nothing.
 *
 * A DRAFT row is deliberately left NULL here: its scope, like its
 * enrollment/failure policy, lives only in its JSON `definition` document
 * until `WorkflowPublisher::publish()` denormalises it onto this column —
 * exactly the same NULL-while-draft convention `enrollment_policy`/
 * `failure_policy` already follow. A draft opened after this backfill reads
 * `location_scope: 'all'` from `WorkflowDefinitionValidator`'s own
 * backward-compatible default when the key is absent from an old document
 * (§5A), not from this column.
 *
 * Idempotent: every UPDATE re-checks `location_scope IS NULL`, so a row a
 * concurrent publish already set is never overwritten. Chunked so a large
 * table is never locked in one statement. Immutable once shipped — a
 * correction is a new V2 class, never an edit here.
 */
class WorkflowVersionLocationScopeBackfillV1
{
    private const CHUNK_SIZE = 500;

    public function run(): int
    {
        $updated = 0;

        DB::table('automation_workflow_versions')
            ->whereIn('state', [WorkflowVersionState::Published->value, WorkflowVersionState::Superseded->value])
            ->whereNull('location_scope')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$updated): void {
                $ids = $rows->pluck('id')->all();

                $updated += DB::table('automation_workflow_versions')
                    ->whereIn('id', $ids)
                    ->whereNull('location_scope')
                    ->update(['location_scope' => WorkflowLocationScope::All->value]);
            });

        return $updated;
    }

    public function unresolvedCount(): int
    {
        return DB::table('automation_workflow_versions')
            ->whereIn('state', [WorkflowVersionState::Published->value, WorkflowVersionState::Superseded->value])
            ->whereNull('location_scope')
            ->count();
    }
}
