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
        /** Telnyx cost_information.upfront_cost, in micro-units; null when the provider quoted none. */
        public ?int $upfrontCostMicro = null,
        /** Telnyx cost_information.monthly_cost, in micro-units. */
        public ?int $monthlyCostMicro = null,
        public ?string $currency = null,
    ) {
    }

    /** A purchase is only ever allowed for a number with a complete, USD quote. */
    public function hasQuote(): bool
    {
        return $this->upfrontCostMicro !== null && $this->monthlyCostMicro !== null && $this->currency === 'USD';
    }

    /** Carrier cost charged at order: the upfront cost plus the first month's rental. */
    public function initialCostMicro(): int
    {
        return (int) $this->upfrontCostMicro + (int) $this->monthlyCostMicro;
    }
}
