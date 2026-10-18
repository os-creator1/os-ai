<?php

namespace App\Library\GoogleAds\Mutations;

/**
 * The Business is not entitled to PlatformFeature::GoogleAdsModule (the
 * decision comes from EntitlementManager, never from a plan name). Map to
 * 404 (contract §7: every route beyond Overview/Settings fails closed).
 */
final class GoogleAdsMutationNotEntitledException extends GoogleAdsMutationException
{
    public function __construct(string $reason = 'not_entitled')
    {
        parent::__construct($reason, 'Google Ads management is not available for this business.');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
