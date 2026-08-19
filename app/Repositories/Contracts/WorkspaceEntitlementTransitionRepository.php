<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspaceEntitlementTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-004 §10.4/§21) — deliberately no update() method,
 * mirroring WorkspaceTransitionRepository's exact precedent (RFC-003).
 */
interface WorkspaceEntitlementTransitionRepository extends BaseRepository
{
    public function create(array $attributes): WorkspaceEntitlementTransition;

    /**
     * @return Collection<int, WorkspaceEntitlementTransition>
     */
    public function forWorkspace(int $workspaceId): Collection;

    /**
     * RFC-004 Amendment 1 §6/§8 — the single indexed lookup
     * allocateAdditionalBusinessSlotsFromVerifiedPayment()'s own
     * idempotency check uses.
     */
    public function findByPaymentIdempotencyKey(string $key): ?WorkspaceEntitlementTransition;
}
