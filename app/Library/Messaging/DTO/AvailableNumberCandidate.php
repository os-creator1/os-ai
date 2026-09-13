<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\PhoneNumberType;

/**
 * Text messaging setup/number/compliance hub — one number the provider
 * reported as available to order, before it is ever purchased. Carries
 * only what the confirmation step needs to show and what provisionNumber()
 * needs to place the actual order; no provider search metadata beyond
 * that opaque reference.
 */
final readonly class AvailableNumberCandidate
{
    public function __construct(
        public string $phoneNumber,
        public PhoneNumberType $numberType,
        public string $providerCandidateReference,
    ) {
    }
}
