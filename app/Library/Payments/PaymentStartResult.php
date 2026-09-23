<?php

namespace App\Library\Payments;

/**
 * Implementation Contract 17 §7.2.1 — exactly what PAY START hands the
 * browser, and nothing more.
 *
 * `clientSecret` is transient: it is serialized into one HTTP response and
 * never persisted, logged, or written into an exception or audit row. The
 * `connectedAccountId` and `publishableKey` are the connected-account context
 * Stripe.js needs, both derived SERVER-SIDE from the payment row's own
 * `business_stripe_connection_id` (§7.2.2) — a browser cannot choose them.
 *
 * The platform SECRET key is never here.
 */
final readonly class PaymentStartResult
{
    public function __construct(
        public string $paymentUid,
        public string $clientSecret,
        public string $connectedAccountId,
        public string $publishableKey,
        public int $amountMinor,
        public string $currencyCode,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'payment_uid' => $this->paymentUid,
            'client_secret' => $this->clientSecret,
            'stripe_account' => $this->connectedAccountId,
            'publishable_key' => $this->publishableKey,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->currencyCode,
        ];
    }
}
