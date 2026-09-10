<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\Business;
use App\Models\BusinessUsageMeasurement;
use App\Repositories\Contracts\BusinessUsageMeasurementRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Slice 3 §4.8 — the ONLY code in the codebase that writes
 * business_usage_measurements.
 *
 * Reached exclusively through UsageWalletManager::recordMeasurement(); no
 * class under app/Library/Messaging/** holds a reference to this repository,
 * the model, or the table (T-MSG-48).
 *
 * Idempotency is guaranteed by the table's own UNIQUE(idempotency_key), not
 * by a read-then-write race: a concurrent duplicate loses the insert and is
 * resolved to the winner's row.
 *
 * It writes no rate, activation, reservation, classification or ledger row —
 * recording that something happened is not charging for it.
 */
class EloquentBusinessUsageMeasurementRepository extends EloquentBaseRepository implements BusinessUsageMeasurementRepository
{
    public function __construct(BusinessUsageMeasurement $measurement)
    {
        parent::__construct($measurement);
    }

    public function recordOnce(
        Business $business,
        PlatformFeature $featureKey,
        string $quantity,
        string $unit,
        string $idempotencyKey,
        ?string $transportMarker = null,
    ): BusinessUsageMeasurement {
        // Scoped by Business AND feature, matching the table's own unique
        // index exactly. A global lookup on the caller-chosen key alone
        // returned ANOTHER Business's row whenever two Businesses used the
        // same string — attributing one tenant's usage to another and
        // silently discarding the second measurement.
        $existing = $this->findScopedByIdempotencyKey($business, $featureKey, $idempotencyKey);

        if ($existing instanceof BusinessUsageMeasurement) {
            return $existing;
        }

        $now = Carbon::now();

        try {
            /** @var BusinessUsageMeasurement $measurement */
            $measurement = $this->make([
                'business_id' => (int) $business->id,
                'feature_key' => $featureKey->value,
                'quantity' => $quantity,
                'unit' => $unit,
                'transport_marker' => $transportMarker,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            $measurement->save();

            return $measurement;
        } catch (UniqueConstraintViolationException) {
            // A genuinely concurrent caller won the same key; converge on
            // the row it wrote rather than surfacing a database error.
            $winner = $this->findScopedByIdempotencyKey($business, $featureKey, $idempotencyKey);

            if ($winner instanceof BusinessUsageMeasurement) {
                return $winner;
            }

            throw new \RuntimeException(
                'business_usage_measurements rejected a duplicate idempotency key that then could not be read back.',
            );
        }
    }

    /**
     * The scoped lookup every write path uses. Its WHERE clause is the
     * table's unique index, column for column, so a row found here is by
     * construction the row the insert would have collided with.
     */
    public function findScopedByIdempotencyKey(
        Business $business,
        PlatformFeature $featureKey,
        string $idempotencyKey,
    ): ?BusinessUsageMeasurement {
        $found = $this->query()
            ->where('business_id', (int) $business->id)
            ->where('feature_key', $featureKey->value)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($found === null) {
            return null;
        }

        // Belt and braces after retrieval, as the audit asks: a row handed
        // back to a caller must provably belong to the Business and feature
        // that caller asked about, whatever the query did.
        // `feature_key` is cast to the PlatformFeature enum on the model, so
        // it is normalized back to its scalar before comparison rather than
        // compared against a string it can never equal.
        $foundFeature = $found->feature_key instanceof PlatformFeature
            ? $found->feature_key->value
            : (string) $found->feature_key;

        if ((int) $found->business_id !== (int) $business->id || $foundFeature !== $featureKey->value) {
            throw new \RuntimeException(
                'business_usage_measurements returned a row belonging to another Business or feature.',
            );
        }

        return $found;
    }

    /**
     * Unscoped lookup, retained for administrative/diagnostic callers that
     * genuinely hold a globally unique key.
     *
     * It is NOT used by any write path: the key alone is caller-chosen and
     * therefore not unique across Businesses, so resolving idempotency
     * through it is precisely the defect this class no longer has.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?BusinessUsageMeasurement
    {
        return $this->query()->where('idempotency_key', $idempotencyKey)->first();
    }
}
