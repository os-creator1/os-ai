<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by migration 8 when, after processing, a non-zero count of
 * PlatformFeature cases still lack a platform_feature_usage_classifications
 * row (M1 contract §9.1), mirroring UsageWalletBackfillIncompleteException's
 * exact-count discipline.
 */
class PlatformFeatureUsageClassificationBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly int $remainingUnclassifiedCount)
    {
        parent::__construct(
            "Platform feature usage classification backfill incomplete: {$remainingUnclassifiedCount} feature(s) still have no classification row."
        );
    }
}
