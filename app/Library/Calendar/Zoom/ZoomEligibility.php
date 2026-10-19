<?php

namespace App\Library\Calendar\Zoom;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Who may use native Zoom meetings. A Business is eligible when EITHER
 *   1. its own Workspace holds an ACTIVE Growth or Agency plan (Growth Businesses, an Agency's own operating Business, the
 *      internal MotionGrove Business with its complimentary Growth plan); OR
 *   2. its Workspace is an ACTIVELY MANAGED Client Workspace of an Agency Workspace that itself holds an ACTIVE Agency plan.
 *
 * Why (2): an Agency may resell only Core or Growth tiers, and a Client Workspace's entitlement is its own plan row, so a
 * client on a Core resale plan would otherwise be locked out of a capability the Agency's own subscription includes.
 * Requiring every client to buy Growth is not a product requirement, so a managed client is covered by its Agency's
 * Agency-tier plan, only while the management relationship is active and the Agency's plan is active.
 *
 * Isolation is unchanged: this is a read-only yes/no. Each Business still needs its own Zoom authorization by one of its own
 * users, no connection is ever shared across Businesses or with the Agency, and view-as cannot connect or disconnect.
 * Nothing is written: no Stripe, price, entitlement or subscription row. Core outside an Agency is not eligible. A dedicated
 * platform feature key is the proper long-term gate (docs/product/ZOOM-MEETINGS.md).
 */
final class ZoomEligibility
{
    public const ELIGIBLE_TIERS = [WorkspacePlanTier::Growth, WorkspacePlanTier::Agency];

    public function allows(Business $business): bool
    {
        if ($this->tierOf((int) $business->workspace_id, self::ELIGIBLE_TIERS)) {
            return true;
        }

        $agencyWorkspaceId = DB::table('agency_client_workspace_relationships')
            ->where('client_workspace_id', $business->workspace_id)
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->value('agency_workspace_id');

        return $agencyWorkspaceId !== null && $this->tierOf((int) $agencyWorkspaceId, [WorkspacePlanTier::Agency]);
    }

    /** @param  list<WorkspacePlanTier>  $tiers */
    private function tierOf(int $workspaceId, array $tiers): bool
    {
        $tier = DB::table('workspace_plan_assignments as a')
            ->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')
            ->where('a.workspace_id', $workspaceId)
            ->where('a.status', 'active')
            ->value('c.tier');

        return in_array($tier, array_map(fn (WorkspacePlanTier $t) => $t->value, $tiers), true);
    }
}
