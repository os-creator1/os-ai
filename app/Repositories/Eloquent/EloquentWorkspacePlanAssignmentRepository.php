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

    public function findByWorkspaceId(int $workspaceId): ?WorkspacePlanAssignment
    {
        return $this->query()->where('workspace_id', $workspaceId)->first();
    }

    public function create(array $attributes): WorkspacePlanAssignment
    {
        /** @var WorkspacePlanAssignment $assignment */
        $assignment = $this->make($attributes);
        $assignment->save();

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
