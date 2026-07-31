<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceTransitionRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-003 Milestone 2 domain contract, corrected report §5) —
 * deliberately no update() method.
 */
class EloquentWorkspaceTransitionRepository extends EloquentBaseRepository implements WorkspaceTransitionRepository
{
    public function __construct(WorkspaceTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): WorkspaceTransition
    {
        /** @var WorkspaceTransition $transition */
        $transition = $this->make(Arr::only($attributes, [
            'workspace_id',
            'transition_type',
            'actor_user_id',
            'from_owner_user_id',
            'to_owner_user_id',
            'previous_owner_disposition',
            'business_id',
            'from_workspace_id',
        ]));
        $transition->save();

        return $transition;
    }

    public function forWorkspace(int $workspaceId): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspaceId)
            ->orWhere('from_workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
