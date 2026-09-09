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
        $existing = $this->findByIdempotencyKey($idempotencyKey);

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
            $winner = $this->findByIdempotencyKey($idempotencyKey);

            if ($winner instanceof BusinessUsageMeasurement) {
                return $winner;
            }

            throw new \RuntimeException(
                'business_usage_measurements rejected a duplicate idempotency key that then could not be read back.',
            );
        }
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?BusinessUsageMeasurement
    {
        return $this->query()->where('idempotency_key', $idempotencyKey)->first();
    }
}
