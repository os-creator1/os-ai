<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use Illuminate\Support\Facades\Log;

/**
 * M4 contract §9 item 5/§22 — dispatched (not scheduled), fired whenever
 * createScheduledRenewalCharge() computes a renewal amount differing from
 * the prior period's own recorded amount. No notification-delivery
 * infrastructure exists in this codebase yet (M4 contract §3 items 16–17,
 * a pre-existing M1–M3 gap) — this job's own scope is structural
 * dispatch-and-record only; it invents no delivery mechanism.
 */
class SendSlotAgreementPriceChangeNotice extends Base
{
    public function __construct(
        private readonly int $agreementId,
        private readonly int $renewalChargeId,
    ) {
    }

    public function handle(): void
    {
        Log::info('Additional-slot agreement renewal price changed.', [
            'agreement_id' => $this->agreementId,
            'renewal_charge_id' => $this->renewalChargeId,
        ]);
    }
}
