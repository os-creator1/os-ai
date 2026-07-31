<?php

namespace App\Repositories\Eloquent;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EloquentWorkspaceRepository extends EloquentBaseRepository implements WorkspaceRepository
{
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

    public function businessesForWorkspace(Workspace $workspace): Collection
    {
        return $workspace->businesses()->get();
    }
}
