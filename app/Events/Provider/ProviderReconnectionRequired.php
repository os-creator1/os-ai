<?php

namespace App\Events\Provider;

use Illuminate\Foundation\Events\Dispatchable;

/** An advertising-provider connection became unusable (revoked or expired) and the owner must reconnect it. Raised by the Google Ads and Meta connection managers at their single state-transition seam. */
final class ProviderReconnectionRequired
{
    use Dispatchable;

    /** @param string $provider google_ads | meta_ads */
    public function __construct(public readonly int $businessId, public readonly string $provider, public readonly string $state)
    {
    }
}
