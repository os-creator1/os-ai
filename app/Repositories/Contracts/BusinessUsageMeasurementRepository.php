<?php

namespace App\Repositories\Contracts;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\Business;
use App\Models\BusinessUsageMeasurement;

/**
 * Slice 3 §4.8 — plain data-access contract for RFC-005's additive,
 * measurement-only table.
 *
 * Measurement is not accounting: nothing here reserves, debits, prices or
 * settles anything. The implementation is the only code in the codebase
 * permitted to write business_usage_measurements, and it is reached only
 * through UsageWalletManager::recordMeasurement().
 */
interface BusinessUsageMeasurementRepository extends BaseRepository
{
    /**
     * Idempotent by $idempotencyKey: a repeat call with the same key returns
     * the row already recorded and writes nothing further.
     */
    public function recordOnce(
        Business $business,
        PlatformFeature $featureKey,
        string $quantity,
        string $unit,
        string $idempotencyKey,
        ?string $transportMarker = null,
    ): BusinessUsageMeasurement;

    public function findByIdempotencyKey(string $idempotencyKey): ?BusinessUsageMeasurement;
}
