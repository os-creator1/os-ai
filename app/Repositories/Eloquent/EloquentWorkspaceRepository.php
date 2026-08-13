<?php

namespace App\Repositories\Eloquent;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EloquentWorkspaceRepository extends EloquentBaseRepository implements WorkspaceRepository
{
    private const MAX_ADMIN_PER_PAGE = 100;

    public function __construct(Workspace $workspace)
    {
        parent::__construct($workspace);
    }

    public function findById(int $id): ?Workspace
    {
        return $this->query()->find($id);
    }

    public function findForUpdate(int $id): ?Workspace
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByUid(string $uid): ?Workspace
    {
        return $this->query()->where('uid', $uid)->first();
    }

    public function findOwnedBy(int $userId): Collection
    {
        return $this->query()->where('owner_user_id', $userId)->get();
    }

    public function allForUser(int $userId): Collection
    {
        return $this->query()
            ->where('owner_user_id', $userId)
            ->orWhereIn('id', function ($query) use ($userId) {
                $query->select('workspace_id')
                    ->from('workspace_memberships')
                    ->where('user_id', $userId)
                    ->where('is_active', true);
            })
            ->orderBy('id')
            ->get();
    }

    public function create(array $attributes): Workspace
    {
        /** @var Workspace $workspace */
        $workspace = $this->make($attributes);
        $workspace->save();

        return $workspace;
    }

    public function update(Workspace $workspace, array $attributes): Workspace
    {
        $workspace->fill(Arr::only($attributes, ['name']));
        $workspace->save();

        return $workspace;
    }

    public function setActive(Workspace $workspace, bool $isActive): Workspace
    {
        $workspace->is_active = $isActive;
        $workspace->save();

        return $workspace;
    }

    public function transferOwnership(Workspace $workspace, int $newOwnerUserId): Workspace
    {
        $workspace->owner_user_id = $newOwnerUserId;
        $workspace->save();

        return $workspace;
    }

    public function businessesForWorkspace(Workspace $workspace): Collection
    {
        return $workspace->businesses()->get();
    }

    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $filters = Arr::only($filters, ['search', 'is_active']);
        $perPage = max(1, min($perPage, self::MAX_ADMIN_PER_PAGE));

        $query = $this->query()
            ->with('owner')
            ->withCount([
                'businesses',
                'memberships as active_memberships_count' => function ($query) {
                    $query->where('is_active', true);
                },
            ]);

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('uid', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                        $ownerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // filled() treats both true and false as present — only a missing
        // (null) key means "no filter" here.
        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // Deterministic — newest first, ties impossible since id is a unique
        // monotonic key (unlike name/created_at, which can collide).
        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
}
