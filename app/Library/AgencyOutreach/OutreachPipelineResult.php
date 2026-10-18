<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectMessage;

/**
 * What OutreachSendPipeline did. Either the message was NOT claimed (`skipReason` says
 * why, nothing was sent or recorded as an attempt) or it was claimed and sent/settled
 * (`send` is the outcome, `row` the ledger row).
 */
final class OutreachPipelineResult
{
    public function __construct(
        public readonly ?string $skipReason = null,
        public readonly ?OutreachSendResult $send = null,
        public readonly ?AgencyProspectMessage $row = null,
    ) {
    }

    public function claimed(): bool
    {
        return $this->skipReason === null;
    }

    public function sent(): bool
    {
        return $this->send?->isSent() === true;
    }
}
