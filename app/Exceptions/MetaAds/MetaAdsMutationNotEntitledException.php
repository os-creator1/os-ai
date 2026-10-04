<?php

namespace App\Exceptions\MetaAds;

/**
 * The Business does not hold the full Ads capability (decided by
 * AdsFeatureAccess, never a plan name). Map to 404: every route beyond
 * Overview/Settings fails closed.
 */
final class MetaAdsMutationNotEntitledException extends MetaAdsMutationException
{
    public function __construct(string $reason = 'not_entitled')
    {
        parent::__construct($reason, 'Meta Ads management is not available for this business.');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
