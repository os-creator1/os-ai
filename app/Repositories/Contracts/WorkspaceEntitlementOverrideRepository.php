<?php

namespace App\Repositories\Contracts;

use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Models\WorkspaceEntitlementOverride;

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
