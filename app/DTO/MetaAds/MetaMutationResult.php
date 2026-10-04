<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §7 — a successful status write: Meta answered
 * `{"success":true}` for `POST /{id}` with the requested status.
 */
final readonly class MetaMutationResult
{
    public function __construct(
        public string $externalId,
        public string $requestedState,
    ) {
        if (! in_array($requestedState, ['PAUSED', 'ACTIVE'], true)) {
            throw new InvalidArgumentException('Requested state must be PAUSED or ACTIVE.');
        }
    }
}
