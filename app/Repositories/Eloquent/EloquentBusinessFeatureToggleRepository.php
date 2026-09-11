<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\BusinessFeatureToggle;
use Illuminate\Support\Collection;
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

    public function allForBusiness(int $businessId): Collection
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->get()
            // feature_key is enum-cast on the model, so the raw attribute is
            // a PlatformFeature here, not a string. Keying by ->value keeps
            // the caller's lookup a plain string comparison either way.
            ->keyBy(static fn (BusinessFeatureToggle $toggle): string => $toggle->feature_key instanceof PlatformFeature
                ? $toggle->feature_key->value
                : (string) $toggle->feature_key);
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
