<?php

namespace App\Repositories\Eloquent;

use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M3 contract §14 — the exact, uniformly-bounded claim/lease/exhaustion
 * algorithm, verbatim from RFC-005 §21 (as corrected).
 */
class EloquentPaymentProviderEventRepository extends EloquentBaseRepository implements PaymentProviderEventRepository
{
    public function __construct(PaymentProviderEvent $event)
    {
        parent::__construct($event);
    }

    public function findByProviderEventId(string $provider, string $providerEventId): ?PaymentProviderEvent
    {
        return $this->query()->where('provider', $provider)->where('provider_event_id', $providerEventId)->first();
    }

    public function findById(int $id): ?PaymentProviderEvent
    {
        return $this->query()->find($id);
    }

    public function create(array $attributes): PaymentProviderEvent
    {
        /** @var PaymentProviderEvent $event */
        $event = $this->make($attributes);
        $event->save();

        return $event;
    }

    public function claim(int $id, int $leaseMinutes, int $maxAttempts): int
    {
        return DB::update(
            <<<'SQL'
                UPDATE payment_provider_events
                SET state = 'processing',
                    processing_started_at = NOW(),
                    lease_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    attempts = attempts + 1,
                    last_attempt_at = NOW()
                WHERE id = ?
                  AND (
                    state = 'received'
                    OR (state = 'failed' AND attempts < ?)
                    OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < ?)
                  )
                SQL,
            [$leaseMinutes, $id, $maxAttempts, $maxAttempts],
        );
    }

    public function markProcessed(int $id): void
    {
        DB::update(
            "UPDATE payment_provider_events SET state = 'processed', completed_at = NOW(), lease_expires_at = NULL, last_error = NULL WHERE id = ? AND state = 'processing'",
            [$id],
        );
    }

    public function markIgnored(int $id): void
    {
        DB::update(
            "UPDATE payment_provider_events SET state = 'ignored', completed_at = NOW(), lease_expires_at = NULL WHERE id = ? AND state = 'processing'",
            [$id],
        );
    }

    public function markFailed(int $id, string $error): void
    {
        DB::update(
            "UPDATE payment_provider_events SET state = 'failed', last_error = ?, lease_expires_at = NULL WHERE id = ? AND state = 'processing'",
            [$error, $id],
        );
    }

    public function dispose(int $id, ?int $disposedByUserId, string $note, int $maxAttempts): int
    {
        return DB::update(
            <<<'SQL'
                UPDATE payment_provider_events
                SET state = 'disposed', disposed_at = NOW(), disposed_by_user_id = ?, disposition_note = ?
                WHERE id = ?
                  AND state IN ('failed', 'processing')
                  AND attempts >= ?
                SQL,
            [$disposedByUserId, $note, $id, $maxAttempts],
        );
    }

    public function exhausted(int $maxAttempts): Collection
    {
        return $this->query()
            ->where(function ($query) use ($maxAttempts) {
                $query->where('state', 'failed')->where('attempts', '>=', $maxAttempts);
            })
            ->orWhere(function ($query) use ($maxAttempts) {
                $query->where('state', 'processing')->where('lease_expires_at', '<', now())->where('attempts', '>=', $maxAttempts);
            })
            ->orderBy('id')
            ->get();
    }

    public function purgeable(int $retentionDays): Collection
    {
        $cutoff = now()->subDays($retentionDays);

        return $this->query()
            ->whereNull('payload_purged_at')
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($inner) use ($cutoff) {
                    $inner->whereIn('state', ['processed', 'ignored'])->where('completed_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->where('state', 'disposed')->where('disposed_at', '<', $cutoff);
                });
            })
            ->get();
    }

    public function purgePayload(int $id): void
    {
        $this->query()->where('id', $id)->update([
            'payload_encrypted' => null,
            'payload_purged_at' => now(),
        ]);
    }
}
