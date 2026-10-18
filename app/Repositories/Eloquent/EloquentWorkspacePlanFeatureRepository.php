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

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — a plan catalog's feature list is admin-managed reference
     * data, asked about repeatedly within one request (the controller's
     * own entitlement check and, independently, the menu/shell's
     * snapshot); this memoizes it for the life of the current request
     * only. create() below invalidates the same key.
     */
    public function featureKeysForCatalog(WorkspacePlanCatalog $catalog): Collection
    {
        return $this->rememberForRequest(
            "workspace_plan_feature:keys:{$catalog->id}",
            fn () => $this->query()
                ->where('workspace_plan_catalog_id', $catalog->id)
                ->pluck('feature_key')
                ->map(fn ($featureKey) => $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey),
        );
    }

    public function includesFeature(WorkspacePlanCatalog $catalog, string $featureKey): bool
    {
        return $this->featureKeysForCatalog($catalog)->contains($featureKey);
    }

    public function create(array $attributes): WorkspacePlanFeature
    {
        $this->guardKnownFeatureKey($attributes['feature_key'] ?? null);

        /** @var WorkspacePlanFeature $feature */
        $feature = $this->make($attributes);
        $feature->save();
        $this->forgetRequestCache("workspace_plan_feature:keys:{$feature->workspace_plan_catalog_id}");

        return $feature;
    }

    public function syncFeatureKeys(WorkspacePlanCatalog $catalog, array $featureKeys): array
    {
        $wanted = array_values(array_unique($featureKeys));

        foreach ($wanted as $key) {
            $this->guardKnownFeatureKey($key);
        }

        $current = $this->query()->where('workspace_plan_catalog_id', $catalog->id)->get()
            ->map(fn ($row) => $row->feature_key instanceof PlatformFeature ? $row->feature_key->value : (string) $row->feature_key)
            ->all();

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        foreach ($added as $key) {
            $this->create(['workspace_plan_catalog_id' => $catalog->id, 'feature_key' => $key]);
        }

        if ($removed !== []) {
            $this->query()->where('workspace_plan_catalog_id', $catalog->id)->whereIn('feature_key', $removed)->delete();
        }

        $this->forgetRequestCache("workspace_plan_feature:keys:{$catalog->id}");

        return ['added' => $added, 'removed' => $removed];
    }

    private function guardKnownFeatureKey(mixed $featureKey): void
    {
        $value = $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey;

        if (! is_string($value) || PlatformFeature::tryFrom($value) === null) {
            throw new InvalidArgumentException("Unknown PlatformFeature key [{$value}].");
        }
    }
}
