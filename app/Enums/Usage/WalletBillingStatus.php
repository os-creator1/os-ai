<?php

namespace App\Enums\Usage;

enum WalletBillingStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
