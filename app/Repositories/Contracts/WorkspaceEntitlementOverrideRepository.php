<?php

namespace App\Repositories\Contracts;

use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Models\WorkspaceEntitlementOverride;
use Illuminate\Support\Collection;

/**
 * Plain data-access contract. Reverting to inherit is a delete of the
 * override row (RFC-004 §10.5) — there is no "inherit" state to write.
 * Durable audit (workspace_entitlement_transitions) and event dispatch for
 * a create/change/revert are exclusively EntitlementManager's
 * responsibility (M2, §15/§21) — this repository never writes either.
 */
interface WorkspaceEntitlementOverrideRepository extends BaseRepository
{
    public function findByWorkspaceAndFeature(int $workspaceId, string $featureKey): ?WorkspaceEntitlementOverride;

    /**
     * Every override this Workspace holds, in one read.
     *
     * Additive, for Slice 2A's menu snapshot (§6.3/§6.5): resolving a menu
     * one feature at a time would issue one findByWorkspaceAndFeature() per
     * entry, and the query budget is fixed regardless of how many entries the
     * menu grows. Overrides are at most one row per PlatformFeature, so this
     * is bounded by the enum, not by tenant data.
     *
     * Data access only — no policy. EntitlementManager remains the single
     * authority on what an override means.
     *
     * @return \Illuminate\Support\Collection<string, WorkspaceEntitlementOverride> keyed by feature_key
     */
    public function allForWorkspace(int $workspaceId): Collection;

    /**
     * $attributes['feature_key'] must be a valid PlatformFeature::cases()
     * value — validated here at the application layer (RFC-004 §10.5).
     */
    public function create(array $attributes): WorkspaceEntitlementOverride;

    public function delete(WorkspaceEntitlementOverride $override): void;

    /**
     * Changes an existing override's state (allow<->deny, RFC-004 §15) — a
     * genuinely distinct operation from creating a first-time override
     * (M2, §10). No business-rule logic here; enforced exclusively by
     * EntitlementManager before it calls this method.
     */
    public function update(WorkspaceEntitlementOverride $override, WorkspaceEntitlementOverrideState $state): WorkspaceEntitlementOverride;
}
