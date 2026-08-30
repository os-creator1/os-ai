<?php

namespace App\Repositories\Contracts;

use App\Models\PaymentProviderEvent;
use Illuminate\Support\Collection;

interface PaymentProviderEventRepository extends BaseRepository
{
    public function findByProviderEventId(string $provider, string $providerEventId): ?PaymentProviderEvent;

    public function findById(int $id): ?PaymentProviderEvent;

    public function create(array $attributes): PaymentProviderEvent;

    /**
     * The atomic claim UPDATE (M3 contract §14) — returns the number of
     * rows the UPDATE actually matched (0 or 1), never loading the row
     * itself, so the claim's own atomicity is never split across two
     * statements.
     */
    public function claim(int $id, int $leaseMinutes, int $maxAttempts): int;

    /**
     * RFC-005 Remediation #6 §18 — $attribution optionally carries the
     * durable, administrator-visible normalized_* columns, built directly
     * from a ProviderOutcomeResult's own fields. An empty array (the
     * default) preserves the exact prior behavior for every existing,
     * non-refund/dispute caller.
     */
    public function markProcessed(int $id, array $attribution = []): void;

    public function markIgnored(int $id, array $attribution = []): void;

    public function markFailed(int $id, string $error): void;

    public function dispose(int $id, ?int $disposedByUserId, string $note, int $maxAttempts): int;

    /**
     * @return Collection<int, PaymentProviderEvent>
     */
    public function exhausted(int $maxAttempts): Collection;

    /**
     * @return Collection<int, PaymentProviderEvent>
     */
    public function purgeable(int $retentionDays): Collection;

    public function purgePayload(int $id): void;

    /**
     * RFC-005 Remediation #6 §19 — at normalized $maxAttempts === 0,
     * executes exactly the single received-recovery query and returns at
     * most the clamped $limit rows (no fairness floor, since only one
     * branch is active). At $maxAttempts > 0: one received query, one
     * failed query, one query per eligible stale-processing attempt
     * bucket (2 + $maxAttempts total), two-level fairly interleaved.
     * $limit is clamped to a positive locked maximum, never silently
     * raised — throws \InvalidArgumentException before any query executes
     * if the requested limit is below the required fairness floor
     * (max(3, $maxAttempts), only meaningful when $maxAttempts > 0).
     *
     * @return Collection<int, PaymentProviderEvent>
     */
    public function retryable(int $maxAttempts, int $receivedGraceMinutes, int $limit): Collection;

    /**
     * RFC-005 Remediation #6 §18 — the admin surface's own bounded read,
     * clamped on both sides. Ordered normalized_recorded_at DESC, id DESC.
     *
     * @return Collection<int, PaymentProviderEvent>
     */
    public function recentOutcomes(int $limit = 50): Collection;
}
