<?php

namespace App\DTO\GoogleBusinessProfile;

/**
 * GBP Slice A contract §7.1/§20.3 — the read-only Verifications v1
 * VoiceOfMerchantState signals. `hasOwnershipConflict` reflects the
 * presence of the resolveOwnershipConflict object, which Google defines as
 * an EMPTY object: presence itself is the signal.
 */
final readonly class GoogleVoiceOfMerchantState
{
    public function __construct(
        public ?bool $hasVoiceOfMerchant,
        public ?bool $hasBusinessAuthority,
        public bool $hasPendingVerification,
        public bool $isWaitingForVoiceOfMerchant,
        public bool $hasOwnershipConflict,
        public ?string $complianceReason,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, false, false, false, null);
    }
}
