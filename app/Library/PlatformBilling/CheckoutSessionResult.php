<?php

namespace App\Library\PlatformBilling;

/**
 * Implementation Contract 21 §7/§12 — the normalized result of a hosted
 * subscription checkout.
 *
 * `url` is the only thing the browser is ever sent to. `subscriptionId` and
 * `customerId` are null until the provider has actually created them, which is
 * precisely why §7's "no successful provider result → no fabricated paid
 * Active state" is expressible here rather than merely hoped for.
 */
final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $sessionId,
        public ?string $url = null,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        /** Stripe's own session status: open / complete / expired. */
        public ?string $status = null,
        /** Our durable local identity, echoed back by the provider. */
        public ?string $clientReferenceId = null,
    ) {
    }
}
