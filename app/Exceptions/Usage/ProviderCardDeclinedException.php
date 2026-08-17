<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway when the
 * provider declines a card. declineCode is a Stripe-defined, non-sensitive
 * classification string (e.g. "insufficient_funds") — never the card
 * number, CVC, or any part of it.
 */
class ProviderCardDeclinedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $declineCode,
    ) {
        parent::__construct('Payment provider declined the card.'.($declineCode !== null ? " Decline code: {$declineCode}." : ''));
    }
}
