<?php

namespace App\Library\AgencyBilling;

/**
 * Lane C §C4 — the normalized state of an Agency's connected Stripe account.
 *
 * Nothing Stripe-shaped travels past this: no `Stripe\Account`, no raw payload,
 * no API key. `requirementsDisabledReason` is the provider's own machine code
 * (e.g. `requirements.past_due`), never its prose, so nothing a provider wrote
 * can reach a screen.
 */
final readonly class AgencyAccountSnapshot
{
    public function __construct(
        public string $stripeAccountId,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public ?string $requirementsDisabledReason = null,
        public ?string $defaultCurrency = null,
    ) {
    }
}
