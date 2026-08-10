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

    /**
     * Row-locking variant of findById(), for callers that must hold the
     * row lock for the duration of a transaction (RFC-003 Milestone 2
     * domain contract, corrected report §8).
     */
    public function findForUpdate(int $id): ?WorkspaceMembership;

    public function findByWorkspaceAndUser(Workspace $workspace, int $userId): ?WorkspaceMembership;

    /**
     * Row-locking variant of findByWorkspaceAndUser(), for callers that
     * must hold the row lock for the duration of a transaction (RFC-003
     * Milestone 2 domain contract, corrected report §5/§8) — used by
     * transferOwnership() to lock both the incoming and previous owner's
     * membership rows deterministically.
     */
    public function findByWorkspaceAndUserForUpdate(int $workspaceId, int $userId): ?WorkspaceMembership;

    public function activeForWorkspace(Workspace $workspace): Collection;

    /**
     * Every WorkspaceMembership for this Workspace, active and inactive
     * alike (RFC-003 Milestone 4 Slice 4B) — used only by manager-facing
     * surfaces that must also see and reactivate inactive members.
     * activeForWorkspace() is untouched and remains the active-only read.
     */
    public function allForWorkspace(Workspace $workspace): Collection;

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
