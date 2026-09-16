<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspacePlanAssignment;
use Carbon\CarbonInterface;

/**
 * Plain data-access contract — no effective-entitlement, slot-capacity, or
 * billing-state decision logic. Those are exclusively EntitlementManager's
 * responsibility (M2, RFC-004 §14/§17/§18/§20).
 */
interface WorkspacePlanAssignmentRepository extends BaseRepository
{
    public function findByWorkspaceId(int $workspaceId): ?WorkspacePlanAssignment;

    public function create(array $attributes): WorkspacePlanAssignment;

    /**
     * Plain data-access mutation — no pricing-invariant/business-rule
     * logic here. Every invariant (slot-value bounds, status transitions,
     * complimentary provenance) is enforced exclusively by
     * EntitlementManager before it calls this method (M2, RFC-004 §20).
     */
    public function update(WorkspacePlanAssignment $assignment, array $attributes): WorkspacePlanAssignment;

    /**
     * A locking/current read — deliberately NOT an ordinary
     * consistent-snapshot exists() query. Reports whether at least one
     * non-complimentary assignment currently references this catalog row,
     * guaranteeing visibility of a row committed on another connection
     * before the caller's catalog-row lock was acquired, even under
     * MySQL/InnoDB REPEATABLE READ. Required by
     * EntitlementManager::updateCatalogPricing()'s clearing guard (M2,
     * RFC-004 §12.5). No entitlement/business logic here — existence only.
     */
    public function hasNonComplimentaryForCatalogForUpdate(int $catalogId): bool;

    /**
     * Contract 03 §7 — a locking, CURRENT read of one Workspace's assignment,
     * deliberately NOT the request-memoized findByWorkspaceId(): a caller
     * already holding the Workspace row lock must decide on the row as it is
     * now, not on a copy memoized earlier in the same request or console
     * process. Drops that memoized copy too, so a later findByWorkspaceId()
     * in the same request re-reads instead of serving the stale one. Same
     * "locking/current read" shape as hasNonComplimentaryForCatalogForUpdate().
     * No entitlement/business logic here.
     */
    public function findByWorkspaceIdForUpdate(int $workspaceId): ?WorkspacePlanAssignment;

    /**
     * Contract 03 §7, sweep 1 — Workspaces whose trial is OUTSTANDING and has
     * run out: `status = active AND trial_ends_at IS NOT NULL AND
     * trial_ends_at <= $cutoff AND grace_started_at IS NULL AND locked_at IS
     * NULL`.
     *
     * The `trial_ends_at IS NOT NULL` clause is the whole point: once
     * recoverAccess() has cleared the column on conversion, the row can never
     * match again, so a converted customer can never be swept back into
     * Grace by an old trial date.
     *
     * Plain data access, exactly like every other method here — the caller
     * (AdvanceWorkspaceAccountLifecycle, via EntitlementManager) owns the
     * decision of what to do with these ids and supplies the cutoff.
     *
     * @return array<int, int> workspace ids, ascending
     */
    public function findWorkspaceIdsWithOutstandingTrialEndedBy(CarbonInterface $cutoff): array;

    /**
     * Contract 03 §7, sweep 2 — Workspaces whose Grace window opened at or
     * before `$cutoff` and are not locked yet: `status = active AND
     * grace_started_at IS NOT NULL AND grace_started_at <= $cutoff AND
     * locked_at IS NULL`.
     *
     * The caller subtracts the Grace length (EntitlementManager's own
     * GRACE_PERIOD_DAYS) to produce `$cutoff`, so the window's length stays
     * a single business constant in one place rather than an interval
     * duplicated into SQL here.
     *
     * @return array<int, int> workspace ids, ascending
     */
    public function findWorkspaceIdsWithGraceStartedBy(CarbonInterface $cutoff): array;
}
