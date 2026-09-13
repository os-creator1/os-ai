<?php

namespace App\Repositories\Eloquent;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Models\WorkspaceEntitlementOverride;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use InvalidArgumentException;

class EloquentWorkspaceEntitlementOverrideRepository extends EloquentBaseRepository implements WorkspaceEntitlementOverrideRepository
{
    public function __construct(WorkspaceEntitlementOverride $override)
    {
        parent::__construct($override);
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — the controller's own entitlement check and the menu/shell's
     * entitlement snapshot both ask about this same Workspace's overrides
     * within one request; this memoizes each shape (single-feature and
     * all-features) for the life of the current request only. create()/
     * update()/delete() below invalidate both.
     */
    public function findByWorkspaceAndFeature(int $workspaceId, string $featureKey): ?WorkspaceEntitlementOverride
    {
        return $this->rememberForRequest(
            "workspace_entitlement_override:find:{$workspaceId}:{$featureKey}",
            fn () => $this->query()
                ->where('workspace_id', $workspaceId)
                ->where('feature_key', $featureKey)
                ->first(),
        );
    }

    public function allForWorkspace(int $workspaceId): Collection
    {
        return $this->rememberForRequest(
            "workspace_entitlement_override:all:{$workspaceId}",
            fn () => $this->query()
                ->where('workspace_id', $workspaceId)
                ->get()
                // feature_key is enum-cast on the model (see the toggle
                // repository's sibling read) — key by ->value so the caller can
                // always look up with a plain string.
                ->keyBy(static fn (WorkspaceEntitlementOverride $override): string => $override->feature_key instanceof PlatformFeature
                    ? $override->feature_key->value
                    : (string) $override->feature_key),
        );
    }

    public function create(array $attributes): WorkspaceEntitlementOverride
    {
        $this->guardKnownFeatureKey($attributes['feature_key'] ?? null);

        /** @var WorkspaceEntitlementOverride $override */
        $override = $this->make($attributes);
        $override->save();
        $this->forgetOverrideCache($override);

        return $override;
    }

    public function delete(WorkspaceEntitlementOverride $override): void
    {
        $override->delete();
        $this->forgetOverrideCache($override);
    }

    public function update(WorkspaceEntitlementOverride $override, WorkspaceEntitlementOverrideState $state): WorkspaceEntitlementOverride
    {
        $override->state = $state;
        $override->save();
        $this->forgetOverrideCache($override);

        return $override;
    }

    private function forgetOverrideCache(WorkspaceEntitlementOverride $override): void
    {
        $featureKey = $override->feature_key instanceof PlatformFeature ? $override->feature_key->value : (string) $override->feature_key;
        $this->forgetRequestCache("workspace_entitlement_override:find:{$override->workspace_id}:{$featureKey}");
        $this->forgetRequestCache("workspace_entitlement_override:all:{$override->workspace_id}");
    }

    private function guardKnownFeatureKey(mixed $featureKey): void
    {
        $value = $featureKey instanceof PlatformFeature ? $featureKey->value : $featureKey;

        if (! is_string($value) || PlatformFeature::tryFrom($value) === null) {
            throw new InvalidArgumentException("Unknown PlatformFeature key [{$value}].");
        }
    }
}
