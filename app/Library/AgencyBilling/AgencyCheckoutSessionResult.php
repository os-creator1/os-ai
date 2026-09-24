<?php

namespace App\Library\AgencyBilling;

/**
 * Lane C §C7 — a hosted Checkout Session on the AGENCY's connected account.
 *
 * `url` is where the CLIENT goes to type their card. It is the only thing this
 * application ever holds about that card, which is the structural form of "no
 * card detail reaches us".
 */
final readonly class AgencyCheckoutSessionResult
{
    public function __construct(
        public string $sessionId,
        public ?string $url = null,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        public ?string $status = null,
        public ?string $clientReferenceId = null,
    ) {
    }
}
