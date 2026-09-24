<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use Carbon\CarbonInterface;

/**
 * Lane C §C4 — the normalized value object that crosses lane C's provider
 * boundary.
 *
 * `connectedAccountId` TRAVELS WITH EVERY SNAPSHOT. Lane C's whole safety story
 * is that a subscription can only ever be moved by its own Agency's account, so
 * the account is part of the observation rather than context the caller is
 * trusted to remember.
 *
 * `periodStart`/`periodEnd` are normalized by the gateway: in the current
 * Stripe API those live on the subscription ITEM, while older pinned versions
 * expose them on the subscription itself. Resolving that is the gateway's job.
 */
final readonly class AgencySubscriptionSnapshot
{
    public function __construct(
        public string $providerSubscriptionId,
        public string $providerCustomerId,
        public string $connectedAccountId,
        public AgencyClientSubscriptionStatus $status,
        public ?string $providerPriceId = null,
        public ?CarbonInterface $periodStart = null,
        public ?CarbonInterface $periodEnd = null,
        public ?CarbonInterface $trialEndsAt = null,
        public bool $cancelAtPeriodEnd = false,
        public ?CarbonInterface $canceledAt = null,
        public ?CarbonInterface $endedAt = null,
        /** Our own durable row UID, echoed back through provider metadata. */
        public ?string $operationId = null,
    ) {
    }
}
