<?php

namespace App\Library\GoogleAds\Mutations;

/**
 * The target (campaign / keyword / search term / ad group) or the Business's
 * selected Ads account does not exist INSIDE this Business. A uid belonging
 * to another Business is indistinguishable from a missing one. Map to 404.
 */
final class GoogleAdsMutationNotFoundException extends GoogleAdsMutationException
{
    public function __construct(string $reason = 'target_not_found')
    {
        parent::__construct($reason, 'That item was not found.');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
