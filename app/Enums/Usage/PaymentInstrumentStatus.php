<?php

namespace App\Enums\Usage;

/**
 * business_payment_instruments.status (RFC-005 §17.B, M3 contract §21.2).
 * Detach only ever flips active -> detached; a row is never deleted.
 */
enum PaymentInstrumentStatus: string
{
    case Active = 'active';
    case Detached = 'detached';
}
