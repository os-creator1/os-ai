<?php

namespace App\Events\Business;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BusinessUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $changedFields  Names of the fields that changed. Never carries old/new values.
     */
    public function __construct(
        public readonly int $businessId,
        public readonly array $changedFields,
    ) {
    }
}
