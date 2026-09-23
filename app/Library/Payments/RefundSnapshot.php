<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentRefundStatus;

/**
 * Implementation Contract 17 §7.4 / §8.7 — the normalized shape a provider
 * refund takes once it has crossed the lane-B gateway.
 *
 * `status` is already OUR local vocabulary (pending / succeeded / failed);
 * Stripe's own refund status strings die inside ProviderStatusMap, exactly as
 * they do for PaymentIntents (§11.8).
 *
 * There is no client secret here and never will be: a refund is entirely
 * server-to-server, with no browser step at all.
 */
final readonly class RefundSnapshot
{
    public function __construct(
        public string $providerRefundId,
        public BusinessDocumentRefundStatus $status,
        public int $amountMinor,
        public string $currencyCode,
        public string $connectedAccountId,
        public ?string $operationId = null,
        public ?string $providerChargeId = null,
    ) {
    }
}
