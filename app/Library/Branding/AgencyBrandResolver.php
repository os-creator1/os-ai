<?php

namespace App\Library\Branding;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Customer Experience Slice 2 — resolves the Agency white-label brand for
 * an unauthenticated request, or nothing.
 *
 * Authority (contract §9.1, §18): the only tenancy signal consulted is the
 * server-resolved request host. Query parameters, submitted Workspace
 * uids, session values and database order are never read. Whatever an
 * AgencyBrandSource returns is then re-checked against the authoritative
 * Workspace record: it must exist, be active and be on the Agency tier
 * through an active plan assignment (the same join Slice 1B's
 * CustomerContextSnapshot uses). Any failure — including an exception in
 * the source — yields null, and the caller falls back to the neutral or
 * owner-platform identity. No result is cached: with no source bound
 * this costs nothing, and with one bound the per-request lookup is one
 * indexed query, so no cache key can ever be shared across Workspaces.
 */
class AgencyBrandResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    public function resolve(Request $request): ?AgencyBrand
    {
        if (! $this->container->bound(AgencyBrandSource::class)) {
            return null;
        }

        $host = strtolower(trim($request->getHost()));

        if ($host === '') {
            return null;
        }

        try {
            $brand = $this->container->make(AgencyBrandSource::class)->forHost($host);
        } catch (Throwable) {
            return null;
        }

        if (! $brand instanceof AgencyBrand || trim($brand->displayName) === '') {
            return null;
        }

        return $this->isAuthorizedAgencyWorkspace($brand->workspaceUid) ? $brand : null;
    }

    /**
     * The Workspace named by the source must currently exist, be active
     * and hold an active Agency-tier plan assignment. A deactivated,
     * deleted, downgraded or unassigned Workspace never brands a screen.
     */
    private function isAuthorizedAgencyWorkspace(string $workspaceUid): bool
    {
        $workspaceUid = trim($workspaceUid);

        if ($workspaceUid === '') {
            return false;
        }

        try {
            return DB::table('workspaces as w')
                ->join('workspace_plan_assignments as pa', function (JoinClause $join): void {
                    $join->on('pa.workspace_id', '=', 'w.id')
                        ->where('pa.status', '=', WorkspacePlanAssignmentStatus::Active->value);
                })
                ->join('workspace_plan_catalog as pc', 'pc.id', '=', 'pa.workspace_plan_catalog_id')
                ->where('w.uid', $workspaceUid)
                ->where('w.is_active', 1)
                ->where('pc.tier', WorkspacePlanTier::Agency->value)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
