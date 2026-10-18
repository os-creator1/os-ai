<?php

namespace App\Library\GoogleAds\Mutations;

/** The actor does not hold `manage_google_ads`. Map to 403. */
final class GoogleAdsMutationForbiddenException extends GoogleAdsMutationException
{
    public function __construct()
    {
        parent::__construct('missing_permission', 'You do not have permission to change Google Ads.');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
