<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\UsageMeter;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class EloquentUsageMeterRepository extends EloquentBaseRepository implements UsageMeterRepository
{
    public function __construct(UsageMeter $meter)
    {
        parent::__construct($meter);
    }

    public function findByMeterKey(string $meterKey): ?UsageMeter
    {
        return $this->query()->where('meter_key', $meterKey)->first();
    }

    public function create(array $attributes): UsageMeter
    {
        if (PlatformFeature::tryFrom((string) ($attributes['feature_key'] ?? '')) === null) {
            throw new InvalidArgumentException(
                "UsageMeter requires a valid PlatformFeature feature_key, got: '{$attributes['feature_key']}'."
            );
        }

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException('UsageMeter requires a non-empty description.');
        }

        if (empty($attributes['updated_by_user_id'])) {
            throw new InvalidArgumentException('UsageMeter requires a genuine actor (updated_by_user_id).');
        }

        /** @var UsageMeter $meter */
        $meter = $this->make($attributes);
        $meter->save();

        return $meter;
    }

    public function update(UsageMeter $meter, array $attributes): UsageMeter
    {
        $meter->fill(Arr::only($attributes, [
            'active_rate_id',
            'is_metered',
            'description',
            'updated_by_user_id',
        ]));
        $meter->save();

        return $meter;
    }
}
