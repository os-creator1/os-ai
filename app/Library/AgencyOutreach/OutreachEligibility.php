<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\Workspace;

/**
 * May this member be texted AT THIS INSTANT? (contract §6). Every automatic send —
 * reply, follow-up, opener, resumed paused send — asks this under the member's row
 * lock, against freshly read rows, never against what was true when the job was queued.
 *
 * Returns the exact reason a send must not happen (recorded on the ledger), or the
 * loaded context when it may. Reasons, in the order they are checked:
 *
 *   member_missing / member_terminal   nothing to send to, or booked / rejected / opted out
 *   tenancy_mismatch                   member, campaign and prospect do not share one Workspace
 *   workspace_inactive, not_entitled   the Workspace may not use Outreach
 *   campaign_not_managed / _not_active only an ACTIVE `managed` campaign uses this engine
 *   prospect_not_active                stopped or booked
 *   no_business                        the Agency's own Business is not resolvable
 *   opted_out                          the number is on the Agency Business's Blacklists
 *   manual_hold                        AI is paused for this member (only when asked)
 */
final class OutreachEligibility
{
    public function __construct(private readonly EntitlementManager $entitlements)
    {
    }

    public function check(?AgencyProspectCampaignMember $member, bool $requireAiActive = true): OutreachEligibilityResult
    {
        if ($member === null) {
            return OutreachEligibilityResult::refused('member_missing');
        }

        if ($member->isTerminal()) {
            return OutreachEligibilityResult::refused('member_terminal');
        }

        // Read fresh: a relation cached on a model passed in from a queue payload is stale.
        $campaign = AgencyProspectCampaign::query()->find($member->campaign_id);
        $prospect = AgencyProspect::query()->find($member->prospect_id);
        $workspace = Workspace::query()->find($member->workspace_id);

        if ($campaign === null || $prospect === null || $workspace === null
            || (int) $campaign->workspace_id !== (int) $member->workspace_id
            || (int) $prospect->workspace_id !== (int) $member->workspace_id) {
            return OutreachEligibilityResult::refused('tenancy_mismatch');
        }

        if (! $workspace->is_active) {
            return OutreachEligibilityResult::refused('workspace_inactive');
        }

        if (! $this->entitlements->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed) {
            return OutreachEligibilityResult::refused('not_entitled');
        }

        if (! $campaign->isManaged()) {
            return OutreachEligibilityResult::refused('campaign_not_managed');
        }

        if ($campaign->status !== AgencyProspectCampaignStatus::Active) {
            return OutreachEligibilityResult::refused('campaign_not_active');
        }

        if ($prospect->status !== AgencyProspectStatus::Active) {
            return OutreachEligibilityResult::refused('prospect_not_active');
        }

        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        if ($business === null) {
            return OutreachEligibilityResult::refused('no_business');
        }

        if (self::blacklisted($business, $prospect)) {
            return OutreachEligibilityResult::refused('opted_out');
        }

        if ($requireAiActive && $member->isAiPaused()) {
            return OutreachEligibilityResult::refused('manual_hold');
        }

        return OutreachEligibilityResult::allowed($workspace, $campaign, $prospect, $business);
    }

    public static function blacklisted(Business $business, AgencyProspect $prospect): bool
    {
        $digits = preg_replace('/\D/', '', (string) $prospect->phone) ?? '';

        return $digits !== ''
            && Blacklists::query()->where('business_id', (int) $business->id)->where('number', $digits)->exists();
    }
}
