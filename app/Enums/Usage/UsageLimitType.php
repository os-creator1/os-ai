<?php

namespace App\Enums\Usage;

enum UsageLimitType: string
{
    case BusinessSpendCap = 'business_spend_cap';
    case FeatureLimit = 'feature_limit';
    case PlatformSafetyLimit = 'platform_safety_limit';
}
