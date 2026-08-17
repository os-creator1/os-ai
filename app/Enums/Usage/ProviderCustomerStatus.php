<?php

namespace App\Enums\Usage;

/**
 * payment_provider_customers.status (RFC-005 §17.B, M3 contract §21.1).
 * Detach only ever flips active -> detached; a row is never deleted.
 */
enum ProviderCustomerStatus: string
{
    case Active = 'active';
    case Detached = 'detached';
}
