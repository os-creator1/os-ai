<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;

/**
 * Implementation Contract 17 §7.2.1 / §11.8 — the normalized shape a
 * PaymentIntent takes once it has crossed the lane-B gateway.
 *
 * `status` is already OUR local vocabulary: the provider's own status string
 * is mapped inside the gateway and never travels further (§11.8), so no
 * manager, finalizer, controller or Blade file ever sees one.
 *
 * `clientSecret` is TRANSIENT BROWSER MATERIAL. It lives on this object only
 * for the length of one request, is handed straight to the payment-start
 * response, and is never persisted, logged, cross-checked, or written into an
 * exception (§5.9, §11.8). It is deliberately nullable: every server-side path
 * that does not need it (a webhook, a reconciliation) gets null.
 */
final readonly class PaymentIntentSnapshot
{
    public function __construct(
        public string $providerPaymentIntentId,
        public BusinessDocumentPaymentStatus $status,
        public int $amountMinor,
        public string $currencyCode,
        public string $connectedAccountId,
        public ?string $operationId,
        public ?string $providerChargeId = null,
        public ?string $failureCode = null,
        public ?string $clientSecret = null,
    ) {
    }

    /**
     * The same snapshot with the transient secret stripped — used everywhere
     * the value must not travel, so dropping it is an explicit act rather
     * than something a caller has to remember.
     */
    public function withoutClientSecret(): self
    {
        return new self(
            $this->providerPaymentIntentId,
            $this->status,
            $this->amountMinor,
            $this->currencyCode,
            $this->connectedAccountId,
            $this->operationId,
            $this->providerChargeId,
            $this->failureCode,
            null,
        );
    }
}
