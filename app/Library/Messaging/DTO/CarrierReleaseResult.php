<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\CarrierReleaseOutcome;

/**
 * Phone Numbers + A2P lane — MessagingProvisioningAdapter::releaseNumber()'s
 * result. $detail is a short, non-sensitive label for the audit trail (an
 * HTTP status class, a transport-error marker) — never the raw provider
 * response body and never a credential, matching this codebase's existing
 * "no result carries the raw provider body or a credential" convention
 * (see TelnyxAdapterAndKillSwitchTest).
 */
final readonly class CarrierReleaseResult
{
    public function __construct(
        public CarrierReleaseOutcome $outcome,
        public ?string $detail = null,
    ) {
    }
}
