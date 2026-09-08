<?php

namespace App\Library\Navigation;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The navigation shell's read model of "which Workspaces and Businesses
 * can this actor see", built with ONE SQL statement.
 *
 * WHY ONE STATEMENT. The shell renders on every customer page, including
 * pages whose existing tests hold a strict query budget (for example the
 * B5 Analytics overview: at most 12 tenancy-plus-KPI queries, of which 11
 * are already spent by the page itself). Walking the repositories
 * (allForUser → businessesForWorkspace → membership → assignments) costs
 * two to four statements per Workspace and would break that budget, so
 * the candidate listing is one joined SELECT.
 *
 * WHAT IT IS NOT. This is a presentation read model, never an
 * authorization boundary (contract §18 S-3). The `accessible` flag mirrors
 * RFC-003 §14.1 only so that the switcher lists the right candidates; the
 * canonical decision for anything that matters remains
 * WorkspaceManager::userCanAccessBusiness(), which every Business route
 * already enforces and which CustomerContextResolver re-runs for any
 * selection that did not come from a controller-authorized route.
 *
 * Population matches WorkspaceRepository::allForUser(): Workspaces the
 * actor owns, plus Workspaces reached through an ACTIVE membership.
 * Inactive Workspaces are listed (so the shell can explain the state) but
 * none of their Businesses is accessible — exactly §14.1.
 */
final class CustomerContextSnapshot
{
    /**
     * @return array<int, WorkspaceCandidate> ordered by workspaces.id ascending
     */
    public function forUser(int $userId): array
    {
        $rows = DB::table('workspaces as w')
            ->leftJoin('workspace_memberships as m', function (JoinClause $join) use ($userId): void {
                $join->on('m.workspace_id', '=', 'w.id')->where('m.user_id', '=', $userId);
            })
            ->leftJoin('businesses as b', 'b.workspace_id', '=', 'w.id')
            ->leftJoin('workspace_membership_businesses as mb', function (JoinClause $join): void {
                $join->on('mb.workspace_membership_id', '=', 'm.id')->on('mb.business_id', '=', 'b.id');
            })
            ->leftJoin('workspace_plan_assignments as pa', function (JoinClause $join): void {
                $join->on('pa.workspace_id', '=', 'w.id')
                    ->where('pa.status', '=', WorkspacePlanAssignmentStatus::Active->value);
            })
            ->leftJoin('workspace_plan_catalog as pc', 'pc.id', '=', 'pa.workspace_plan_catalog_id')
            ->where(function ($query) use ($userId): void {
                $query->where('w.owner_user_id', $userId)
                    ->orWhere(function ($membership): void {
                        $membership->whereNotNull('m.id')->where('m.is_active', 1);
                    });
            })
            ->orderBy('w.id')
            ->orderBy('b.id')
            ->get([
                'w.id as workspace_id',
                'w.uid as workspace_uid',
                'w.name as workspace_name',
                'w.is_active as workspace_is_active',
                'w.owner_user_id as workspace_owner_user_id',
                'm.id as membership_id',
                'm.role as membership_role',
                'm.business_access_scope as membership_scope',
                'm.is_active as membership_is_active',
                'pc.tier as plan_tier',
                'pc.display_name as plan_display_name',
                'b.id as business_id',
                'b.uid as business_uid',
                'b.name as business_name',
                'b.status as business_status',
                'b.customer_id as business_customer_id',
                'b.is_primary as business_is_primary',
                'mb.business_id as assigned_business_id',
            ]);

        $workspaces = [];
        $businessesByWorkspace = [];

        foreach ($rows as $row) {
            $workspaceId = (int) $row->workspace_id;

            if (! isset($workspaces[$workspaceId])) {
                $workspaces[$workspaceId] = $row;
                $businessesByWorkspace[$workspaceId] = [];
            }

            if ($row->business_id === null) {
                continue;
            }

            $businessesByWorkspace[$workspaceId][] = $row;
        }

        $candidates = [];

        foreach ($workspaces as $workspaceId => $row) {
            $isOwner = (int) $row->workspace_owner_user_id === $userId;
            $membershipActive = $row->membership_id !== null && (bool) $row->membership_is_active;
            $role = $row->membership_role !== null ? WorkspaceMembershipRole::tryFrom((string) $row->membership_role) : null;
            $scope = $row->membership_scope !== null ? WorkspaceBusinessAccessScope::tryFrom((string) $row->membership_scope) : null;
            $workspaceActive = (bool) $row->workspace_is_active;

            $businesses = [];

            foreach ($businessesByWorkspace[$workspaceId] as $businessRow) {
                $accessible = $workspaceActive && (
                    (int) $businessRow->business_customer_id === $userId
                    || $isOwner
                    || ($membershipActive && (
                        $scope === WorkspaceBusinessAccessScope::All
                        || $businessRow->assigned_business_id !== null
                    ))
                );

                $businesses[] = new BusinessCandidate(
                    id: (int) $businessRow->business_id,
                    uid: (string) $businessRow->business_uid,
                    name: (string) $businessRow->business_name,
                    status: (string) $businessRow->business_status,
                    customerId: (int) $businessRow->business_customer_id,
                    isPrimary: (bool) $businessRow->business_is_primary,
                    accessible: $accessible,
                    workspaceUid: (string) $row->workspace_uid,
                    workspaceName: (string) $row->workspace_name,
                );
            }

            $candidates[] = new WorkspaceCandidate(
                id: $workspaceId,
                uid: (string) $row->workspace_uid,
                name: (string) $row->workspace_name,
                isActive: $workspaceActive,
                ownerUserId: (int) $row->workspace_owner_user_id,
                isOwner: $isOwner,
                membershipRole: $role,
                membershipScope: $scope,
                membershipActive: $membershipActive,
                tier: $row->plan_tier !== null ? WorkspacePlanTier::tryFrom((string) $row->plan_tier) : null,
                tierDisplayName: $row->plan_display_name !== null ? (string) $row->plan_display_name : null,
                businesses: $businesses,
            );
        }

        return $candidates;
    }
}
