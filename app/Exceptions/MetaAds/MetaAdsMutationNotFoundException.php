<?php

namespace App\Exceptions\MetaAds;

/**
 * The target (campaign / ad set / ad) or the Business's SELECTED ad account
 * does not exist INSIDE this Business. Another Business's uid is
 * indistinguishable from a missing one. Map to 404.
 *
 * reason: `target_not_found` | `account_not_found`.
 */
final class MetaAdsMutationNotFoundException extends MetaAdsMutationException
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
