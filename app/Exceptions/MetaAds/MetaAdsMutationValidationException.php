<?php

namespace App\Exceptions\MetaAds;

/**
 * Well-formed but cannot be sent. Nothing was sent. Map to 422.
 *
 * reason: `entity_not_changeable` (DELETED / ARCHIVED / unknown status),
 * `invalid_transition` (not ACTIVE->PAUSED or PAUSED->ACTIVE),
 * `ad_not_resumable` (ad is DISAPPROVED / PENDING_REVIEW).
 */
final class MetaAdsMutationValidationException extends MetaAdsMutationException
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
