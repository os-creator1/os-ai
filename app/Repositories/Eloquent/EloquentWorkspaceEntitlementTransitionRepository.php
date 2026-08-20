<?php

namespace App\Repositories\Eloquent;

use App\Models\WorkspaceEntitlementTransition;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
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
}
