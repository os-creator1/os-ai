<?php

namespace App\Exceptions\MetaAds;

/** The actor does not hold `manage_meta_ads`. Map to 403. */
final class MetaAdsMutationForbiddenException extends MetaAdsMutationException
{
    public function __construct()
    {
        parent::__construct('missing_permission', 'You do not have permission to change Meta Ads.');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
