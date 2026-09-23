<?php

namespace App\Library\Payments;

/**
 * Implementation Contract 17 §12.D — the NORMALIZED shape every lane-B
 * provider call returns.
 *
 * No `Stripe\*` object ever escapes the gateway: the manager, the controller
 * and the views only ever see this. That is what keeps the SDK a detail of
 * app/Library/Payments/** (§4.2's second, lane-B-owned Stripe boundary)
 * rather than a dependency of the domain.
 *
 * It carries ONLY the identifiers and capability material §5.7 authorizes —
 * account id, the three capability booleans, a disabled reason and the
 * default currency. No secret, no API key, no raw provider payload, no
 * requirements detail, no person or KYC data is represented here at all.
 */
final readonly class ConnectedAccountSnapshot
{
    public function __construct(
        public string $stripeAccountId,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public ?string $requirementsDisabledReason,
        public ?string $defaultCurrency,
    ) {
    }
}
