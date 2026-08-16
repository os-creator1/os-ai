<?php

namespace App\Exceptions\Usage;

use RuntimeException;

class NoActiveRateForFeatureException extends RuntimeException
{
    public function __construct(public readonly string $featureKey)
    {
        parent::__construct("No active business_usage_rate is configured for feature '{$featureKey}'.");
    }
}
