<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.7 — connections are HISTORICAL records.
 * pending / onboarding / active / restricted are the live states that hold
 * business_stripe_connections.active_business_id (one live connection per
 * Business); disconnected is terminal and frees it.
 */
enum StripeConnectionStatus: string
{
    case Pending = 'pending';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Restricted = 'restricted';
    case Disconnected = 'disconnected';
}
