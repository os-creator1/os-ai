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
        public ?int $upfrontCostMicro = null,
        public ?int $monthlyCostMicro = null,
        public ?string $costCurrency = null,
        /** True when the carrier accepted the order but it is not finished (or its outcome was not seen). */
        public bool $processing = false,
        public ?string $parentOrderId = null,
        /** The committed wallet reservation that paid for this order (never re-reserved). */
        public ?int $reservationId = null,
    ) {
    }
}
