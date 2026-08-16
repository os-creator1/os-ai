<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;

/**
 * RFC-005 §14 — a coarse, non-mutating gate, always returning exactly
 * usage_unauthorized externally on denial. Delegates internally to
 * UsageWalletManager::evaluateCoarseCapacity(), whose internal-only
 * UsageCapacityDecision reason is never surfaced past this boundary.
 *
 * Bound in place of NullUsageAuthorizationGateway at M1 (RFC-005 §36 item
 * 1's own explicit instruction) — provably behaviorally identical to Null
 * for the entire M1 deployment window, since every PlatformFeature
 * classification stays is_metered=false until M5 (M1 contract §11).
 */
final class RealUsageAuthorizationGateway implements UsageAuthorizationGateway
{
    public function __construct(private readonly UsageWalletManager $manager)
    {
    }

    public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult
    {
        $decision = $this->manager->evaluateCoarseCapacity($business, $feature);

        if (! $decision->authorized) {
            return new UsageAuthorizationResult(authorized: false, reason: 'usage_unauthorized');
        }

        return new UsageAuthorizationResult(authorized: true);
    }
}
