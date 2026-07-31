<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspaceTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-003 Milestone 2 domain contract, corrected report §5) —
 * deliberately no update() method.
 */
interface WorkspaceTransitionRepository extends BaseRepository
{
    public function create(array $attributes): WorkspaceTransition;

    /**
     * Includes rows where the requested Workspace is either the current
     * (workspace_id) or prior (from_workspace_id) side of a Business
     * reassignment — so a Workspace's own history includes Businesses it
     * lost, not just ones it currently or newly holds.
     *
     * @return Collection<int, WorkspaceTransition>
     */
    public function forWorkspace(int $workspaceId): Collection;
}
