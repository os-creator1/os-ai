<?php

namespace App\Events\Business;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessServicesSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $serviceIds  IDs of every active service left standing after the sync.
     */
    public function __construct(
        public readonly int $businessId,
        public readonly array $serviceIds,
    ) {
    }
}
