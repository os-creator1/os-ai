<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\BusinessFeatureToggle;
use App\Repositories\Contracts\BusinessFeatureToggleRepository;
use InvalidArgumentException;

class EloquentBusinessFeatureToggleRepository extends EloquentBaseRepository implements BusinessFeatureToggleRepository
{
    public function __construct(BusinessFeatureToggle $toggle)
    {
        parent::__construct($toggle);
    }

    public function findByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureToggle
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->where('feature_key', $featureKey)
            ->first();
    }

    public function create(array $attributes): BusinessFeatureToggle
    {
        $this->guardKnownFeatureKey($attributes['feature_key'] ?? null);

        /** @var BusinessFeatureToggle $toggle */
        $toggle = $this->make($attributes);
        $toggle->save();

        return $toggle;
    }

    public function delete(BusinessFeatureToggle $toggle): void
    {
        $toggle->delete();
    }

    private function guardKnownFeatureKey(mixed $featureKey): void
    {
        $value = $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey;

        if (! is_string($value) || PlatformFeature::tryFrom($value) === null) {
            throw new InvalidArgumentException("Unknown PlatformFeature key [{$value}].");
        }
    }
}
