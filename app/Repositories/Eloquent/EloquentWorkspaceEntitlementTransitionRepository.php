<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspaceEntitlementTransition;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-004 §10.4/§21) — deliberately no update() method.
 */
class EloquentWorkspaceEntitlementTransitionRepository extends EloquentBaseRepository implements WorkspaceEntitlementTransitionRepository
{
    public function __construct(WorkspaceEntitlementTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): WorkspaceEntitlementTransition
    {
        /** @var WorkspaceEntitlementTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function forWorkspace(int $workspaceId): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function findByPaymentIdempotencyKey(string $key): ?WorkspaceEntitlementTransition
    {
        return $this->query()->where('payment_idempotency_key', $key)->first();
    }

    public function recentForWorkspace(int $workspaceId, int $limit): Collection
    {
        return $this->query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    public function paginateAdminActions(int $perPage, ?int $workspaceId = null): Paginator
    {
        $query = $this->query()
            ->whereNotNull('actor_user_id')
            ->whereIn('actor_user_id', function ($subquery) {
                $subquery->select('id')->from('users')->where('is_admin', true);
            });

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return $query
            ->with('workspace:id,uid,name')
            ->orderByDesc('id')
            ->simplePaginate(max(1, min($perPage, 100)));
    }
}
