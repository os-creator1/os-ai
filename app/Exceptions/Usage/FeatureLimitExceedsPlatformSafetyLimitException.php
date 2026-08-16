<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::setFeatureLimit() (M2 contract §6.C, Human
 * requirement 4) when the requested limit exceeds an already-configured
 * platform_feature_usage_safety_limits ceiling for that feature. A
 * customer may always tighten their own limit, never loosen it past a
 * configured platform ceiling.
 */
class FeatureLimitExceedsPlatformSafetyLimitException extends RuntimeException
{
    public function __construct(
        public readonly int $businessId,
        public readonly string $featureKey,
    ) {
        parent::__construct(
            "The requested limit for feature [{$featureKey}] on Business [{$businessId}] exceeds the configured platform safety limit."
        );
    }
}
