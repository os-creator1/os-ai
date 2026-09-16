<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class EloquentWorkspacePlanAssignmentRepository extends EloquentBaseRepository implements WorkspacePlanAssignmentRepository
{
    public function __construct(WorkspacePlanAssignment $assignment)
    {
        parent::__construct($assignment);
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — the assignment is re-read by workspace id both by the
     * controller's own tenancy/entitlement check and, independently, by
     * the menu/shell's entitlement snapshot; this memoizes it for the
     * life of the current request only. create()/update() below
     * invalidate the same key (including the null-result case: a workspace
     * with no assignment yet, cached as null, must not shadow a create()
     * for that same workspace later in the same request).
     */
    public function findByWorkspaceId(int $workspaceId): ?WorkspacePlanAssignment
    {
        return $this->rememberForRequest(
            "workspace_plan_assignment:find:{$workspaceId}",
            fn () => $this->query()->where('workspace_id', $workspaceId)->first(),
        );
    }

    public function create(array $attributes): WorkspacePlanAssignment
    {
        /** @var WorkspacePlanAssignment $assignment */
        $assignment = $this->make($attributes);
        $assignment->save();
        $this->forgetRequestCache("workspace_plan_assignment:find:{$assignment->workspace_id}");

        return $assignment;
    }

    public function update(WorkspacePlanAssignment $assignment, array $attributes): WorkspacePlanAssignment
    {
        $assignment->fill(Arr::only($attributes, [
            'workspace_plan_catalog_id',
            'status',
            'is_complimentary',
            'complimentary_reason',
            'complimentary_granted_by_user_id',
            'complimentary_granted_at',
            'additional_business_slots',
            // Contract 03 §5 — the three lifecycle timestamps. They must be
            // listed here or EntitlementManager's lifecycle writers would
            // silently write nothing: Arr::only() drops whatever is missing,
            // without an error.
            'trial_ends_at',
            'grace_started_at',
            'locked_at',
        ]));
        $assignment->save();
        $this->forgetRequestCache("workspace_plan_assignment:find:{$assignment->workspace_id}");

        return $assignment;
    }

    public function hasNonComplimentaryForCatalogForUpdate(int $catalogId): bool
    {
        return $this->query()
            ->where('workspace_plan_catalog_id', $catalogId)
            ->where('is_complimentary', false)
            ->lockForUpdate()
            ->exists();
    }

    public function findByWorkspaceIdForUpdate(int $workspaceId): ?WorkspacePlanAssignment
    {
        $this->forgetRequestCache("workspace_plan_assignment:find:{$workspaceId}");

        return $this->query()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Contract 03 §7 sweep 1. Selects ids only, outside any lock, so the list
     * CAN go stale while a long sweep works through it — a trial may convert
     * after this query. That is why the caller never writes from this list
     * directly: EntitlementManager::advanceExpiredTrialIntoGrace() re-checks
     * this exact predicate under the Workspace row lock before it writes.
     */
    public function findWorkspaceIdsWithOutstandingTrialEndedBy(CarbonInterface $cutoff): array
    {
        return $this->query()
            ->where('status', WorkspacePlanAssignmentStatus::Active)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $cutoff)
            ->whereNull('grace_started_at')
            ->whereNull('locked_at')
            ->orderBy('workspace_id')
            ->pluck('workspace_id')
            ->all();
    }

    /**
     * Contract 03 §7 sweep 2. Same id-only list, and the same caveat:
     * EntitlementManager::lockElapsedGracePeriod() re-checks it under the
     * lock, so a customer who pays mid-sweep is never locked from this list.
     */
    public function findWorkspaceIdsWithGraceStartedBy(CarbonInterface $cutoff): array
    {
        return $this->query()
            ->where('status', WorkspacePlanAssignmentStatus::Active)
            ->whereNotNull('grace_started_at')
            ->where('grace_started_at', '<=', $cutoff)
            ->whereNull('locked_at')
            ->orderBy('workspace_id')
            ->pluck('workspace_id')
            ->all();
    }
}
