<?php

declare(strict_types=1);

namespace App\Library\Entitlement\Migration;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\WorkspaceEntitlementBackfillIncompleteException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Query-builder-only, versioned backfill action (RFC-004 §25.2-§25.4) that
 * assigns every pre-existing Workspace lacking a workspace_plan_assignments
 * row a complimentary Core assignment with a deterministically derived
 * additional_business_slots value. Adapted from WorkspaceBackfillV1
 * (RFC-003 §10.3) for a genuinely different unit of work — one Workspace,
 * not a customer_id group, so there is no multi-candidate conflict case to
 * detect here.
 *
 * Must never depend on Eloquent models or model events — every read/write
 * goes through DB's query builder, so this class stays safe to invoke
 * directly from a migration with no framework console dependency.
 *
 * Concurrency safety relies on the unique workspace_id constraint on
 * workspace_plan_assignments: two transactions racing the same Workspace
 * both attempt an ordinary insert; the loser's insert throws a QueryException
 * which is narrowly inspected (MySQL driver error code 1062 together with
 * this table's exact unique-constraint name) and, only when it is genuinely
 * that duplicate-workspace_id race, treated as "already assigned" — no
 * second transition row is written and the count is not incremented. Every
 * other database failure (FK violation, NOT NULL violation, truncation,
 * connection error, an unrelated unique violation, etc.) is rethrown
 * unmodified; nothing is broadly suppressed.
 */
class WorkspaceEntitlementBackfillV1
{
    /**
     * Bounded page size for the not-yet-assigned Workspace cursor below, so
     * memory usage stays flat regardless of table size.
     */
    protected const CHUNK_SIZE = 500;

    private const COMPLIMENTARY_REASON =
        'Pre-RFC-004 Workspace — grandfathered complimentary assignment during Milestone 1 backfill';

    public function run(): WorkspaceEntitlementBackfillResult
    {
        $coreCatalogId = $this->coreCatalogId();

        $workspacesProcessed = 0;
        $assignmentsCreated = 0;
        $lastSeenWorkspaceId = 0;

        while (true) {
            $workspaceIds = $this->nextWorkspaceIdPage($lastSeenWorkspaceId);

            if ($workspaceIds->isEmpty()) {
                break;
            }

            foreach ($workspaceIds as $workspaceId) {
                $created = $this->processWorkspace((int) $workspaceId, $coreCatalogId);

                $workspacesProcessed++;

                if ($created) {
                    $assignmentsCreated++;
                }
            }

            $lastSeenWorkspaceId = (int) $workspaceIds->last();
        }

        $remainingUnassignedCount = $this->unassignedWorkspaceQuery()->count();

        if ($remainingUnassignedCount > 0) {
            throw new WorkspaceEntitlementBackfillIncompleteException($remainingUnassignedCount);
        }

        return new WorkspaceEntitlementBackfillResult(
            workspacesProcessed: $workspacesProcessed,
            assignmentsCreated: $assignmentsCreated,
            remainingUnassignedCount: 0,
        );
    }

    private function coreCatalogId(): int
    {
        $id = DB::table('workspace_plan_catalog')
            ->where('tier', WorkspacePlanTier::Core->value)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException(
                'WorkspaceEntitlementBackfillV1 found no seeded Core workspace_plan_catalog row.'
            );
        }

        return (int) $id;
    }

    private function unassignedWorkspaceQuery()
    {
        return DB::table('workspaces')
            ->leftJoin('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'workspaces.id')
            ->whereNull('workspace_plan_assignments.id');
    }

    /**
     * Deterministic ascending cursor: every Workspace id greater than the
     * last one seen that still lacks a workspace_plan_assignments row.
     * Strictly increasing and bounded by the finite id space, so the outer
     * loop always terminates.
     */
    private function nextWorkspaceIdPage(int $afterWorkspaceId): Collection
    {
        return $this->unassignedWorkspaceQuery()
            ->where('workspaces.id', '>', $afterWorkspaceId)
            ->orderBy('workspaces.id')
            ->limit(static::CHUNK_SIZE)
            ->pluck('workspaces.id');
    }

    /**
     * @return bool true when this call created the assignment; false when
     *   it was already assigned (idempotent no-op, including the losing
     *   side of a genuine concurrent race).
     */
    protected function processWorkspace(int $workspaceId, int $coreCatalogId): bool
    {
        return DB::transaction(function () use ($workspaceId, $coreCatalogId) {
            $now = now();

            $businessCount = DB::table('businesses')->where('workspace_id', $workspaceId)->count();
            $additionalBusinessSlots = match (true) {
                $businessCount >= 5 => 2,
                $businessCount === 4 => 1,
                default => 0,
            };

            try {
                DB::table('workspace_plan_assignments')->insert([
                    'workspace_id' => $workspaceId,
                    'workspace_plan_catalog_id' => $coreCatalogId,
                    'status' => WorkspacePlanAssignmentStatus::Active->value,
                    'is_complimentary' => true,
                    'complimentary_reason' => self::COMPLIMENTARY_REASON,
                    'complimentary_granted_by_user_id' => null,
                    'complimentary_granted_at' => $now,
                    'additional_business_slots' => $additionalBusinessSlots,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                if ($this->isWorkspaceIdDuplicateRace($e)) {
                    return false;
                }

                throw $e;
            }

            DB::table('workspace_entitlement_transitions')->insert([
                'workspace_id' => $workspaceId,
                'transition_type' => WorkspaceEntitlementTransitionType::PlanAssigned->value,
                'actor_user_id' => null,
                'from_plan_catalog_id' => null,
                'to_plan_catalog_id' => $coreCatalogId,
                'feature_key' => null,
                'from_override_state' => null,
                'to_override_state' => null,
                'from_additional_business_slots' => null,
                'to_additional_business_slots' => null,
                'from_status' => null,
                'to_status' => null,
                'reason' => self::COMPLIMENTARY_REASON,
                'created_at' => $now,
            ]);

            return true;
        });
    }

    /**
     * Narrowly identifies the one expected race this backfill must treat as
     * "already assigned": a MySQL duplicate-entry error (driver error code
     * 1062, verified against Laravel's actual QueryException::$errorInfo
     * shape in this installed framework version — QueryException extends
     * PDOException and copies $previous->errorInfo, a 3-element
     * [SQLSTATE, driver code, driver message] array) specifically for this
     * table's own workspace_id unique constraint. SQLSTATE '23000' alone is
     * insufficient — it also covers foreign-key violations (1451/1452) and
     * NOT NULL violations (1048) on this same table, so both the driver
     * code AND the exact constraint name are checked together. Every other
     * QueryException (a different error code, or code 1062 against a
     * different constraint) is not matched here and is rethrown by the
     * caller unmodified.
     */
    private function isWorkspaceIdDuplicateRace(QueryException $e): bool
    {
        $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverErrorCode === 1062
            && str_contains($e->getMessage(), 'workspace_plan_assignments_workspace_id_unique');
    }
}
