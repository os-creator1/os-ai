<?php

namespace App\Exceptions\GoogleBusinessProfile;

use RuntimeException;

/**
 * GBP Slice A contract §19.2 step 7 / §12 (C-3) — the chosen Google
 * location is already bound to another BusinessLocation somewhere on this
 * platform.
 *
 * provider_location_resource_name is UNIQUE with no tenant qualifier, so
 * this can collide across Workspaces. The message deliberately carries NO
 * information about which Business holds the other claim: that would be a
 * cross-tenant disclosure. The controller renders it as a plain product
 * message, never a 500.
 */
final class GoogleLocationAlreadyClaimedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('google_location_already_claimed');
    }

    public function userMessage(): string
    {
        return 'This Google location is already connected to another business profile on this platform.';
    }
}
