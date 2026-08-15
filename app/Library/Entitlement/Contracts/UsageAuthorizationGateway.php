<?php

namespace App\Library\Entitlement\Contracts;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\UsageAuthorizationResult;
use App\Models\Business;

interface UsageAuthorizationGateway
{
    public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult;
}
