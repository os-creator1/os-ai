<?php

namespace App\Repositories\Contracts;

use App\Models\WorkspaceEntitlementTransition;
use Illuminate\Contracts\Pagination\Paginator;
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

    /**
     * Platform Owner / Admin V1 — the newest rows for ONE Workspace, newest
     * first, hard-capped at `$limit` (never above 100). Never unbounded, so a
     * support page cannot load a long-lived Workspace's whole trail.
     *
     * @return Collection<int, WorkspaceEntitlementTransition>
     */
    public function recentForWorkspace(int $workspaceId, int $limit): Collection;

    /**
     * Platform Owner / Admin V1 — one page of the trail's HUMAN ADMIN
     * actions: rows whose `actor_user_id` is a platform administrator
     * (`users.is_admin`). System writes (null actor, sweeps, verified
     * payments) and customer-driven writes are not platform-owner actions
     * and are excluded. Newest first, optionally narrowed to one Workspace.
     * Simple pagination (no COUNT) because the trail is append-only and
     * grows without bound.
     */
    public function paginateAdminActions(int $perPage, ?int $workspaceId = null): Paginator;
}
