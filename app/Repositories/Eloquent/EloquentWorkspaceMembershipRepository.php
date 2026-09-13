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

    public function findForUpdate(int $id): ?WorkspaceMembership
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18, Phase 2) — this is the membership half of
     * WorkspaceManager::userCanAccessBusiness(), which Phase 1 left
     * unmemoized while it cached the Workspace/Business row reads either
     * side of it. Memoized per workspace+user for the life of the current
     * request only; every write below that can change what this answers
     * invalidates the same key.
     */
    public function findByWorkspaceAndUser(Workspace $workspace, int $userId): ?WorkspaceMembership
    {
        return $this->rememberForRequest(
            "membership:find:{$workspace->id}:{$userId}",
            fn () => $this->query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $userId)
                ->first(),
        );
    }

    public function findByWorkspaceAndUserForUpdate(int $workspaceId, int $userId): ?WorkspaceMembership
    {
        return $this->query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    public function activeForWorkspace(Workspace $workspace): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->get();
    }

    public function allForWorkspace(Workspace $workspace): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspace->id)
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
        $this->forgetRequestCache("membership:find:{$workspace->id}:{$userId}");

        return $membership;
    }

    public function updateRole(WorkspaceMembership $membership, WorkspaceMembershipRole $role): WorkspaceMembership
    {
        $membership->role = $role;
        $membership->save();
        $this->forgetMembershipCache($membership);

        return $membership;
    }

    public function updateBusinessAccessScope(
        WorkspaceMembership $membership,
        WorkspaceBusinessAccessScope $scope
    ): WorkspaceMembership {
        $membership->business_access_scope = $scope;
        $membership->save();
        $this->forgetMembershipCache($membership);

        return $membership;
    }

    public function setActive(WorkspaceMembership $membership, bool $isActive): WorkspaceMembership
    {
        $membership->is_active = $isActive;
        $membership->save();
        $this->forgetMembershipCache($membership);

        return $membership;
    }

    private function forgetMembershipCache(WorkspaceMembership $membership): void
    {
        $this->forgetRequestCache("membership:find:{$membership->workspace_id}:{$membership->user_id}");
    }
}
