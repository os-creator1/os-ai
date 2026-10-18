<?php

namespace App\Library\PlatformBilling;

use App\Models\PlatformPrivateOfferRedemption;
use App\Models\WorkspacePlanCatalog;

/**
 * Everything that determines WHAT a hosted Checkout sells: the plan, the Stripe
 * Price it charges, the first recurring amount, the trial, and the private
 * offer it belongs to (if any).
 *
 * Normal signup and an accepted Private Offer are the same code path with
 * different terms; this is the seam. `fingerprint()` is the identity of an
 * ATTEMPT — two attempts with different terms must never share a Checkout
 * Session or a provider idempotency key (Stripe errors when a key is reused
 * with different parameters).
 */
final readonly class CheckoutTerms
{
    public function __construct(
        public WorkspacePlanCatalog $catalog,
        public string $providerPriceId,
        /** The recurring amount of the Price the Checkout charges, as a decimal string. */
        public ?string $priceSnapshot,
        public ?int $trialDays,
        public ?PlatformPrivateOfferRedemption $redemption = null,
        /** Unix time the hosted session stops being payable; null keeps the provider default. */
        public ?int $expiresAt = null,
    ) {
    }

    /** The public, catalog-driven terms. */
    public static function forCatalog(WorkspacePlanCatalog $catalog, ?int $trialDays): self
    {
        return new self(
            $catalog,
            (string) $catalog->provider_price_id,
            $catalog->price === null ? null : (string) $catalog->price,
            $trialDays,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->providerPriceId,
            $this->trialDays ?? 'none',
            $this->redemption?->uid ?? 'public',
        ]));
    }
}
