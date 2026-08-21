<?php

namespace App\Events\Usage;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AdditionalBusinessSlotAgreementPaymentRecovered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $agreementId,
    ) {
    }
}
