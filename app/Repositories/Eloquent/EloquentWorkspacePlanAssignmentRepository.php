<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
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
}
