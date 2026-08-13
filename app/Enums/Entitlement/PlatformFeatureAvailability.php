<?php

namespace App\Enums\Entitlement;

enum PlatformFeatureAvailability: string
{
    case Available = 'available';
    case Planned = 'planned';
}
