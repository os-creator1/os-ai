<?php

namespace App\Repositories\Eloquent;

use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M3 contract §14 — the exact, uniformly-bounded claim/lease/exhaustion
 * algorithm, verbatim from RFC-005 §21 (as corrected).
 *
 * RFC-005 Remediation #6 §19 — retryable()'s own MAX_RETRYABLE_LIMIT is
 * this repository's own upper numeric clamp on the caller's requested
 * limit, independent of RetryStuckPaymentProviderEvents::BATCH_LIMIT
 * (which is always <= this value in production).
 */
class EloquentPaymentProviderEventRepository extends EloquentBaseRepository implements PaymentProviderEventRepository
{
    private const MAX_RETRYABLE_LIMIT = 200;

    private const MAX_RECENT_OUTCOMES_LIMIT = 100;

    /**
     * The exact, allow-listed set of durable attribution columns a
     * terminal-state write may populate (RFC-005 Remediation #6 §18).
     */
    private const ATTRIBUTION_COLUMNS = [
        'business_id',
        'funding_attempt_id',
        'normalized_outcome',
        'normalized_status',
        'normalized_reported_amount_micro',
        'normalized_outcome_delta_micro',
        'normalized_wallet_delta_micro',
        'normalized_policy_excess_micro',
        'normalized_currency_code',
        'normalized_reason',
        'normalized_recorded_at',
    ];

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

    public function markProcessed(int $id, array $attribution = []): void
    {
        $this->applyTerminalState($id, 'processed', true, $attribution);
    }

    public function markIgnored(int $id, array $attribution = []): void
    {
        $this->applyTerminalState($id, 'ignored', false, $attribution);
    }

    private function applyTerminalState(int $id, string $state, bool $clearLastError, array $attribution): void
    {
        $setClauses = ['state = ?', 'completed_at = NOW()', 'lease_expires_at = NULL'];
        $bindings = [$state];

        if ($clearLastError) {
            $setClauses[] = 'last_error = NULL';
        }

        foreach (self::ATTRIBUTION_COLUMNS as $column) {
            if (array_key_exists($column, $attribution)) {
                $setClauses[] = "{$column} = ?";
                $bindings[] = $attribution[$column];
            }
        }

        $bindings[] = $id;

        DB::update(
            'UPDATE payment_provider_events SET '.implode(', ', $setClauses).' WHERE id = ? AND state = \'processing\'',
            $bindings,
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

    /**
     * RFC-005 Remediation #6 §19 — see the interface docblock for the full
     * received-only-at-zero / two-level-fairly-interleaved-at-positive
     * design and its exact bounds.
     */
    public function retryable(int $maxAttempts, int $receivedGraceMinutes, int $limit): Collection
    {
        $maxAttempts = max(0, min($maxAttempts, PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING));
        $requestedLimit = max(1, min($limit, self::MAX_RETRYABLE_LIMIT));

        if ($maxAttempts === 0) {
            return $this->receivedRecoveryCandidates($receivedGraceMinutes, $requestedLimit);
        }

        $fairnessFloor = max(3, $maxAttempts);

        if ($requestedLimit < $fairnessFloor) {
            throw new \InvalidArgumentException(
                "retryable() requested limit ({$requestedLimit}) is below the minimum required to fairly cover 3 state-class branches and {$maxAttempts} stale-processing attempt bucket(s): pass a limit of at least {$fairnessFloor}."
            );
        }

        $received = $this->receivedRecoveryCandidates($receivedGraceMinutes, $requestedLimit);

        $failed = DB::table('payment_provider_events')
            ->where('state', 'failed')
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('attempts')->orderBy('id')
            ->limit($requestedLimit)->get();

        $staleProcessingBuckets = [];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $staleProcessingBuckets[] = DB::table('payment_provider_events')
                ->where('state', 'processing')
                ->where('attempts', $attempt)
                ->where('lease_expires_at', '<', now())
                ->orderBy('lease_expires_at')->orderBy('id')
                ->limit($requestedLimit)->get();
        }

        $staleProcessing = $this->interleaveRetryBranches($staleProcessingBuckets, $requestedLimit);

        return $this->interleaveRetryBranches([$received, $failed, $staleProcessing], $requestedLimit);
    }

    /**
     * The received-recovery branch's own query — structurally independent
     * of $maxAttempts entirely, since claim()'s own `state = 'received'`
     * branch carries no attempts condition. Shared by both the
     * $maxAttempts = 0 received-only path and the $maxAttempts > 0
     * three-branch path.
     */
    private function receivedRecoveryCandidates(int $receivedGraceMinutes, int $limit): Collection
    {
        return DB::table('payment_provider_events')
            ->where('state', 'received')
            ->where('received_at', '<', now()->subMinutes($receivedGraceMinutes))
            ->orderBy('received_at')->orderBy('id')
            ->limit($limit)->get();
    }

    /**
     * A fair, deterministic round-robin interleave — never fixed
     * concatenation. One candidate is taken from each branch in turn,
     * repeating, until either $limit is reached or every branch is
     * exhausted. A branch with any remaining candidate is offered a slot
     * in every round it still has one; deduplication is defensive only
     * (branches are mutually exclusive by construction, at both the
     * state-class level and the attempt-bucket level).
     *
     * @param  array<int, Collection>  $branches
     */
    private function interleaveRetryBranches(array $branches, int $limit): Collection
    {
        $branches = array_map(fn ($branch) => $branch->values(), $branches);
        $cursors = array_fill(0, count($branches), 0);
        $selected = collect();
        $seen = [];

        while ($selected->count() < $limit) {
            $madeProgress = false;

            foreach ($branches as $i => $branch) {
                if ($selected->count() >= $limit) {
                    break;
                }
                if ($cursors[$i] >= $branch->count()) {
                    continue;
                }

                $row = $branch[$cursors[$i]];
                $cursors[$i]++;
                $madeProgress = true;

                if (! isset($seen[$row->id])) {
                    $seen[$row->id] = true;
                    $selected->push($row);
                }
            }

            if (! $madeProgress) {
                break;
            }
        }

        return $selected->values();
    }

    public function recentOutcomes(int $limit = 50): Collection
    {
        $limit = max(1, min($limit, self::MAX_RECENT_OUTCOMES_LIMIT));

        return $this->query()
            ->whereNotNull('normalized_outcome')
            ->orderByDesc('normalized_recorded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
