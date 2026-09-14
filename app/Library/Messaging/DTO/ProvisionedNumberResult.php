<?php

namespace App\Library\Messaging\DTO;

/**
 * Text messaging setup/number/compliance hub — the provider's confirmed
 * result of actually placing a number order, never constructed except from
 * a real (or explicitly faked-in-a-test) provider response. A candidate
 * that was only searched, never ordered, cannot produce this object —
 * that is the structural guarantee behind "never fake a successful
 * purchase".
 */
final readonly class ProvisionedNumberResult
{
    public function __construct(
        public string $messagingProfileId,
        public string $providerPhoneNumberId,
        public string $phoneNumber,
    ) {
    }
}
