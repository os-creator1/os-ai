<?php

namespace App\Library\Usage;

/**
 * Internal-only decision object returned by
 * UsageWalletManager::evaluateCoarseCapacity(). Never surfaced past the
 * UsageAuthorizationGateway boundary (RFC-005 §14) — RealUsageAuthorizationGateway
 * collapses this into a plain authorized/usage_unauthorized
 * UsageAuthorizationResult.
 */
final readonly class UsageCapacityDecision
{
    public function __construct(
        public bool $authorized,
        public ?string $reason = null,
    ) {
    }
}
