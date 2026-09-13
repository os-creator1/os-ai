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

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — the controller's own entitlement check and the menu/shell's
     * entitlement snapshot both ask about this same Business's toggles
     * within one request; this memoizes each shape (single-feature and
     * all-features) for the life of the current request only. create()/
     * delete() below invalidate both.
     */
    public function findByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureToggle
    {
        return $this->rememberForRequest(
            "business_feature_toggle:find:{$businessId}:{$featureKey}",
            fn () => $this->query()
                ->where('business_id', $businessId)
                ->where('feature_key', $featureKey)
                ->first(),
        );
    }

    public function allForBusiness(int $businessId): Collection
    {
        return $this->rememberForRequest(
            "business_feature_toggle:all:{$businessId}",
            fn () => $this->query()
                ->where('business_id', $businessId)
                ->get()
                // feature_key is enum-cast on the model, so the raw attribute is
                // a PlatformFeature here, not a string. Keying by ->value keeps
                // the caller's lookup a plain string comparison either way.
                ->keyBy(static fn (BusinessFeatureToggle $toggle): string => $toggle->feature_key instanceof PlatformFeature
                    ? $toggle->feature_key->value
                    : (string) $toggle->feature_key),
        );
    }

    public function create(array $attributes): BusinessFeatureToggle
    {
        $this->guardKnownFeatureKey($attributes['feature_key'] ?? null);

        /** @var BusinessFeatureToggle $toggle */
        $toggle = $this->make($attributes);
        $toggle->save();
        $this->forgetToggleCache($toggle);

        return $toggle;
    }

    public function delete(BusinessFeatureToggle $toggle): void
    {
        $toggle->delete();
        $this->forgetToggleCache($toggle);
    }

    private function forgetToggleCache(BusinessFeatureToggle $toggle): void
    {
        $featureKey = $toggle->feature_key instanceof PlatformFeature ? $toggle->feature_key->value : (string) $toggle->feature_key;
        $this->forgetRequestCache("business_feature_toggle:find:{$toggle->business_id}:{$featureKey}");
        $this->forgetRequestCache("business_feature_toggle:all:{$toggle->business_id}");
    }

    private function guardKnownFeatureKey(mixed $featureKey): void
    {
        $value = $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey;

        if (! is_string($value) || PlatformFeature::tryFrom($value) === null) {
            throw new InvalidArgumentException("Unknown PlatformFeature key [{$value}].");
        }
    }
}
