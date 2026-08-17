<?php

namespace App\Library\Usage;

use App\Enums\Usage\PayerType;

/**
 * M3 contract §11 item 2 — the immutable Business/payer/billing-contact
 * snapshot frozen onto a business_funding_attempts row at creation time,
 * never re-derived from live state afterward.
 */
final readonly class FundingBillingSnapshot
{
    public function __construct(
        public PayerType $payerType,
        public ?string $billingContactName,
        public ?string $billingContactEmail,
        public string $providerCustomerExternalId,
        public int $providerCustomerId,
        public string $paymentMethodDisplay,
    ) {
    }
}
