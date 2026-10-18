<?php

namespace App\Library\GoogleAds\Mutations;

/**
 * The request is well-formed but cannot be sent: removed entity, a keyword
 * that cannot be paused (negative / campaign-level), invalid keyword text,
 * ad-group scope without a search term. Nothing was sent. Map to 422.
 */
final class GoogleAdsMutationValidationException extends GoogleAdsMutationException
{
    public function __construct(string $reason, string $message = 'That change cannot be made.')
    {
        parent::__construct($reason, $message);
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
