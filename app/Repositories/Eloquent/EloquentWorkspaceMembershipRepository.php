<?php

namespace App\Repositories\Eloquent;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Support\Collection;

class EloquentWorkspaceMembershipRepository extends EloquentBaseRepository implements WorkspaceMembershipRepository
{
    public function __construct(WorkspaceMembership $membership)
    {
        parent::__construct($membership);
    }

    public function findById(int $id): ?WorkspaceMembership
    {
        return $this->query()->find($id);
    }

    public function findByWorkspaceAndUser(Workspace $workspace, int $userId): ?WorkspaceMembership
    {
        return $this->query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();
    }

    public function activeForWorkspace(Workspace $workspace): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->get();
    }

    public function activeForUser(int $userId): Collection
    {
        return $this->query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();
    }

    public function create(
        Workspace $workspace,
        int $userId,
        WorkspaceMembershipRole $role,
        WorkspaceBusinessAccessScope $scope
    ): WorkspaceMembership {
        /** @var WorkspaceMembership $membership */
        $membership = $this->query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $userId],
            ['role' => $role, 'business_access_scope' => $scope, 'is_active' => true]
        );

        return $membership;
    }

    public function updateRole(WorkspaceMembership $membership, WorkspaceMembershipRole $role): WorkspaceMembership
    {
        $membership->role = $role;
        $membership->save();

        return $membership;
    }

    public function updateBusinessAccessScope(
        WorkspaceMembership $membership,
        WorkspaceBusinessAccessScope $scope
    ): WorkspaceMembership {
        $membership->business_access_scope = $scope;
        $membership->save();

        return $membership;
    }

    public function setActive(WorkspaceMembership $membership, bool $isActive): WorkspaceMembership
    {
        $membership->is_active = $isActive;
        $membership->save();

        return $membership;
    }
}
