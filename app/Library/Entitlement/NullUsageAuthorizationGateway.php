<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Models\Business;

/**
 * Reserved seam for RFC-005 (Business Usage Billing and Wallets) — always
 * authorizes until a real gateway is bound. No RFC-005 implementation
 * ships in M2 (RFC-004 §19).
 */
final class NullUsageAuthorizationGateway implements UsageAuthorizationGateway
{
    public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult
    {
        return new UsageAuthorizationResult(authorized: true);
    }
}
