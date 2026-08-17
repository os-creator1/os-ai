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

    public function markProcessed(int $id): void;

    public function markIgnored(int $id): void;

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
}
