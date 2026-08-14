<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::createOrChangeOverride() (RFC-004 §11/§15)
 * for an `allow`-state override write against a feature
 * PlatformFeatureRegistry::isAvailable() reports false for — a plan mapping
 * or override can never make an unimplemented feature executable. A `deny`
 * override for the same feature remains permitted (redundant but harmless).
 *
 * Carries only the feature key — never Customer, User or Business names,
 * company, email, phone, or address.
 */
class UnavailablePlatformFeatureOverrideException extends RuntimeException
{
    public function __construct(public readonly string $featureKey)
    {
        parent::__construct("Platform feature [{$featureKey}] is not available and cannot be allow-overridden.");
    }
}
