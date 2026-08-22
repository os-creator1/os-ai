<?php

namespace App\Repositories\Contracts;

use App\Models\UsageMeter;

interface UsageMeterRepository extends BaseRepository
{
    public function findByMeterKey(string $meterKey): ?UsageMeter;

    public function create(array $attributes): UsageMeter;

    /**
     * Narrow, whitelisted mutation only — meter_key, feature_key,
     * business_id, and currency_id can never be changed through this
     * method, regardless of what $attributes contains (RFC-005 Amendment
     * 1 §6).
     */
    public function update(UsageMeter $meter, array $attributes): UsageMeter;
}
