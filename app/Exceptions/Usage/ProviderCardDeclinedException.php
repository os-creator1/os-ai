<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway when the
 * provider declines a card. declineCode is a Stripe-defined, non-sensitive
 * classification string (e.g. "insufficient_funds") — never the card
 * number, CVC, or any part of it.
 *
 * M4 contract §21 (Correction Round 2 §B) — providerPaymentIntentId is the
 * declined PaymentIntent's own id, when the provider's error response
 * carried one (Stripe documents this as populated for a PaymentIntent-
 * involving error, but does not guarantee it — the gateway passes null
 * when absent, and the manager's own §B fail-closed rule handles that
 * case). Optional, backward compatible: every existing M1-M3 call site
 * that constructs or catches this exception without the new parameter is
 * unaffected.
 */
class ProviderCardDeclinedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $declineCode,
        public readonly ?string $providerPaymentIntentId = null,
    ) {
        parent::__construct('Payment provider declined the card.'.($declineCode !== null ? " Decline code: {$declineCode}." : ''));
    }
}
