<?php

namespace App\Events\GoogleBusinessProfile;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * GBP Slice A contract §19.5 — a bounded read-only mirror was refreshed within its TTL.
 *
 * House shape, checked against App\Events\Business\* and
 * App\Events\Usage\*: ShouldDispatchAfterCommit, Dispatchable, readonly
 * promoted SCALARS only — never a model instance, and never anything that
 * could carry a token or Google Content.
 *
 * No listener is registered in Slice A; EventServiceProvider::$listen is
 * NOT modified. These events exist as a seam, and adding a listener
 * without a purpose would be dead code.
 */
class GoogleBusinessProfileMirrorRefreshed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $bindingId,
    ) {
    }
}
