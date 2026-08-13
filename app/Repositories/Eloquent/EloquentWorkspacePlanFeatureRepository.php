<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanFeature;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EloquentWorkspacePlanFeatureRepository extends EloquentBaseRepository implements WorkspacePlanFeatureRepository
{
    public function __construct(WorkspacePlanFeature $feature)
    {
        parent::__construct($feature);
    }

    public function featureKeysForCatalog(WorkspacePlanCatalog $catalog): Collection
    {
        return $this->query()
            ->where('workspace_plan_catalog_id', $catalog->id)
            ->pluck('feature_key')
            ->map(fn ($featureKey) => $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey);
    }

    public function includesFeature(WorkspacePlanCatalog $catalog, string $featureKey): bool
    {
        return $this->query()
            ->where('workspace_plan_catalog_id', $catalog->id)
            ->where('feature_key', $featureKey)
            ->exists();
    }

    public function create(array $attributes): WorkspacePlanFeature
    {
        $this->guardKnownFeatureKey($attributes['feature_key'] ?? null);

        /** @var WorkspacePlanFeature $feature */
        $feature = $this->make($attributes);
        $feature->save();

        return $feature;
    }

    private function guardKnownFeatureKey(mixed $featureKey): void
    {
        $value = $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey;

        if (! is_string($value) || PlatformFeature::tryFrom($value) === null) {
            throw new InvalidArgumentException("Unknown PlatformFeature key [{$value}].");
        }
    }
}
