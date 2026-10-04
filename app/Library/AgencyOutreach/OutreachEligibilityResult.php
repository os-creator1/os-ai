<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\Business;
use App\Models\Workspace;

/**
 * Either the exact reason a send must not happen, or the rows it is allowed to
 * happen against (read fresh by OutreachEligibility).
 */
final class OutreachEligibilityResult
{
    private function __construct(
        public readonly ?string $reason,
        public readonly ?Workspace $workspace = null,
        public readonly ?AgencyProspectCampaign $campaign = null,
        public readonly ?AgencyProspect $prospect = null,
        public readonly ?Business $business = null,
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self($reason);
    }

    public static function allowed(Workspace $workspace, AgencyProspectCampaign $campaign, AgencyProspect $prospect, Business $business): self
    {
        return new self(null, $workspace, $campaign, $prospect, $business);
    }

    public function ok(): bool
    {
        return $this->reason === null;
    }
}
