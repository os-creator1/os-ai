<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;

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
}
