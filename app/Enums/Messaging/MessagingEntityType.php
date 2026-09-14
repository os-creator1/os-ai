<?php

namespace App\Enums\Messaging;

/**
 * Text messaging setup/number/compliance hub — whether the Business
 * registers as a Sole Proprietor (no EIN required, but requires an
 * OTP-PIN identity verification step the registration flow surfaces
 * separately) or under a standard EIN-backed entity.
 */
enum MessagingEntityType: string
{
    case SoleProprietor = 'sole_proprietor';
    case Ein = 'ein';
}
