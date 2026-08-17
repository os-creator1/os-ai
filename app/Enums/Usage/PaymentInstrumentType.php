<?php

namespace App\Enums\Usage;

/**
 * card only, v1 (RFC-005 §26, M3 contract §21.2).
 */
enum PaymentInstrumentType: string
{
    case Card = 'card';
}
