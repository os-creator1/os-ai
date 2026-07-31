<?php

namespace App\Repositories\Contracts;

use App\Models\Workspace;
use Illuminate\Support\Collection;

interface WorkspaceRepository extends BaseRepository
{
    public function findById(int $id): ?Workspace;

    /**
     * Row-locking variant of findById(), for callers that must hold the row
     * lock for the duration of a transaction (RFC-002 read-modify-write
     * pattern, reused per RFC-003 §12.1).
     */
    public function findForUpdate(int $id): ?Workspace;

    public function findByUid(string $uid): ?Workspace;

    /**
     * Workspaces owned via workspaces.owner_user_id only. Workspace
     * membership never counts as ownership (RFC-003 §7.2).
     */
    public function findOwnedBy(int $userId): Collection;

    /**
     * Every Workspace this user can see: owned via owner_user_id, or
     * reached through an active workspace_memberships row (RFC-003
     * Milestone 2 domain contract — allForUser()). Deliberately not an
     * Eloquent relationship on User — it combines two distinct foreign-key
     * paths, not one ordinary relation. Deduplicated, ordered by
     * workspaces.id ascending. Never filters workspaces.is_active: listing
     * and effective Business access (§14.1) are separate concerns, so an
     * inactive Workspace the user owns or actively belongs to still
     * appears here.
     */
    public function allForUser(int $userId): Collection;

    public function create(array $attributes): Workspace;

    public function update(Workspace $workspace, array $attributes): Workspace;

    public function setActive(Workspace $workspace, bool $isActive): Workspace;

    public function businessesForWorkspace(Workspace $workspace): Collection;
}
