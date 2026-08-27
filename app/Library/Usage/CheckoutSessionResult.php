<?php

namespace App\Library\Usage;

/**
 * M4 contract §15a — the gateway's own normalized return shape for
 * createCheckoutSession()/retrieveCheckoutSession(). redirectUrl is
 * nullable: Stripe's own Checkout Session 'url' field exists only while
 * the Session is 'open' — a retrieve of an already-'complete' Session
 * legitimately carries none. providerPaymentMethodId is resolved only on
 * retrieve of a 'complete' Session (via PaymentIntent expansion), never
 * assumed to be the Workspace's locally-saved default.
 */
final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $providerCheckoutSessionId,
        public string $status,
        public string $paymentStatus,
        public ?string $redirectUrl,
        public int $amountMinorUnits,
        public string $currencyCode,
        public string $providerCustomerId,
        public ?string $providerPaymentIntentId,
        public ?string $providerPaymentMethodId,
        public ?string $receiptUrl = null,
        public ?string $receiptChargeId = null,
    ) {
    }
}
