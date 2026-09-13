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

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — a Workspace is re-read by id several times over the course of
     * one Business-scoped request (tenancy check, entitlement decision,
     * menu/shell resolution); this memoizes it for the life of the current
     * request only. Every write method below invalidates both this and
     * findByUid()'s cache entry for the same row.
     */
    public function findById(int $id): ?Workspace
    {
        return $this->rememberForRequest(
            "workspace:find:{$id}",
            fn () => $this->query()->find($id),
        );
    }

    public function findForUpdate(int $id): ?Workspace
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Also populates findById()'s cache entry for the SAME row: a Business-
     * scoped request typically resolves its Workspace by uid once (the
     * route parameter) and then again by id at least once more
     * (userCanAccessBusiness()'s own re-read) — one row, two lookup
     * shapes. Populating both here means whichever runs second is a cache
     * hit regardless of which shape it uses.
     */
    public function findByUid(string $uid): ?Workspace
    {
        $workspace = $this->rememberForRequest(
            "workspace:findByUid:{$uid}",
            fn () => $this->query()->where('uid', $uid)->first(),
        );

        if ($workspace !== null) {
            $this->rememberForRequest("workspace:find:{$workspace->id}", fn () => $workspace);
        }

        return $workspace;
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
        $this->forgetWorkspaceCache($workspace);

        return $workspace;
    }

    public function setActive(Workspace $workspace, bool $isActive): Workspace
    {
        $workspace->is_active = $isActive;
        $workspace->save();
        $this->forgetWorkspaceCache($workspace);

        return $workspace;
    }

    public function transferOwnership(Workspace $workspace, int $newOwnerUserId): Workspace
    {
        $workspace->owner_user_id = $newOwnerUserId;
        $workspace->save();
        $this->forgetWorkspaceCache($workspace);

        return $workspace;
    }

    private function forgetWorkspaceCache(Workspace $workspace): void
    {
        $this->forgetRequestCache("workspace:find:{$workspace->id}");
        $this->forgetRequestCache("workspace:findByUid:{$workspace->uid}");
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — memoized per workspace id for the life of the current
     * request, and each returned Business is ALSO written into
     * EloquentBusinessRepository's own findById() cache namespace (the
     * exact key format that repository uses): a Business-scoped controller
     * typically finds its Business through this collection (by uid) and
     * then re-reads it by id at least once more
     * (userCanAccessBusiness()'s/decide()'s own re-reads) — this closes
     * that gap the same way findByUid() above closes it for Workspace.
     * businesses() itself has no other write path that mutates row
     * membership under this Workspace within a single request outside
     * BusinessRepository's own writes, which already invalidate their own
     * "business:find:*" keys.
     */
    public function businessesForWorkspace(Workspace $workspace): Collection
    {
        $businesses = $this->rememberForRequest(
            "workspace:businesses:{$workspace->id}",
            fn () => $workspace->businesses()->get(),
        );

        foreach ($businesses as $business) {
            $this->rememberForRequest("business:find:{$business->id}", fn () => $business);
        }

        return $businesses;
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
