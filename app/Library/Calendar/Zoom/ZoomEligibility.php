<?php

namespace App\Library\Calendar\Zoom;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Who may use native Zoom meetings. Initially: a Business whose Workspace holds an ACTIVE Growth or Agency plan.
 * That covers Growth Businesses, an Agency's own operating Business and each managed client Business (whose Client
 * Workspace carries its own plan), and the internal MotionGrove Business (a complimentary Growth plan).
 *
 * It reads the existing plan assignment and changes nothing: no Stripe, price, entitlement or subscription row is
 * written. Core is not eligible. A dedicated platform feature key is the proper long-term gate; adding one touches the
 * feature registry, customer permissions and plan packaging, which needs an explicit decision (docs/product/ZOOM-MEETINGS.md).
 */
final class ZoomEligibility
{
    public const ELIGIBLE_TIERS = [WorkspacePlanTier::Growth, WorkspacePlanTier::Agency];

    public function allows(Business $business): bool
    {
        $tier = DB::table('workspace_plan_assignments as a')
            ->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')
            ->where('a.workspace_id', $business->workspace_id)
            ->where('a.status', 'active')
            ->value('c.tier');

        return in_array($tier, array_map(fn (WorkspacePlanTier $t) => $t->value, self::ELIGIBLE_TIERS), true);
    }
}
