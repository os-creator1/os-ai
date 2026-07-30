<?php

namespace App\Repositories\Contracts;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Collection;

interface WorkspaceMembershipRepository extends BaseRepository
{
    public function findById(int $id): ?WorkspaceMembership;

    public function findByWorkspaceAndUser(Workspace $workspace, int $userId): ?WorkspaceMembership;

    public function activeForWorkspace(Workspace $workspace): Collection;

    public function activeForUser(int $userId): Collection;

    /**
     * Idempotent-safe against the (workspace_id, user_id) unique constraint
     * (RFC-003 §9.2, §12.2): a duplicate call returns the existing row
     * rather than surfacing a raw database exception. $scope has no
     * database default (§9.2), so it is always required here, never
     * optional or defaulted.
     */
    public function create(
        Workspace $workspace,
        int $userId,
        WorkspaceMembershipRole $role,
        WorkspaceBusinessAccessScope $scope
    ): WorkspaceMembership;

    public function updateRole(WorkspaceMembership $membership, WorkspaceMembershipRole $role): WorkspaceMembership;

    public function updateBusinessAccessScope(
        WorkspaceMembership $membership,
        WorkspaceBusinessAccessScope $scope
    ): WorkspaceMembership;

    public function setActive(WorkspaceMembership $membership, bool $isActive): WorkspaceMembership;
}
