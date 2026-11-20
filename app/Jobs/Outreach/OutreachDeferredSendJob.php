<?php

namespace App\Jobs\Outreach;

use App\Jobs\Base;
use App\Library\AgencyOutreach\OutreachSendPipeline;

/**
 * Retries ONE rate-limited (deferred) Outreach send at its stored moment. The ledger row keeps its
 * original operation key, so a duplicate run cannot send twice; and the pipeline re-checks
 * eligibility, the stage, the latest inbound, manual takeover/reply, opt-out and campaign state
 * under the member lock before it asks the shared budget again. Never sleeps: if the budget is
 * still short the pipeline defers again with a new delayed job.
 */
class OutreachDeferredSendJob extends Base
{
    public function __construct(private readonly int $messageId)
    {
    }

    public function handle(OutreachSendPipeline $pipeline): void
    {
        $pipeline->resume($this->messageId);
    }
}
