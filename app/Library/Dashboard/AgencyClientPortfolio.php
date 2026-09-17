<?php

namespace App\Library\Dashboard;

use App\Library\Navigation\BusinessCandidate;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Support\Facades\DB;

/**
 * Who an Agency's clients are, for the Agency Account Home portfolio.
 *
 * THE AUTHORITY IS THE RELATIONSHIP, exactly as the Agency Clients surface
 * (AgencyClientsController) already has it: every client comes from an ACTIVE
 * AgencyClientWorkspaceRelationship of THIS Agency Workspace, read through
 * Contract 01's own repository. Never Businesses inside the Agency Workspace
 * — under the V1 topology an Agency Workspace holds exactly one Business, its
 * OWN, so deriving clients from it can only ever return the Agency itself —
 * never Workspace membership, and never context-switcher state.
 *
 * The Agency's own Business is therefore never a portfolio row, a terminated
 * relationship disappears the moment it is terminated, and another Agency's
 * clients are unreachable because the relationship set is keyed by this
 * Agency Workspace's id.
 *
 * EACH CLIENT IS ITS OWN WORKSPACE HOLDING EXACTLY ONE BUSINESS. A client
 * Workspace that does not currently hold exactly one Business is a legacy or
 * mid-repair shape (Contract 10 owns repair): it is OMITTED rather than
 * resolved to an arbitrary Business, so a malformed row can never put another
 * account's Business in front of an Agency.
 *
 * TWO STATEMENTS, WHATEVER THE NUMBER OF CLIENTS: the active relationships,
 * then one joined read of those client Workspaces with their Businesses. No
 * per-client relationship, Workspace or Business query — the portfolio bands
 * downstream (status flags, cross-client contacts and conversations) are each
 * one grouped statement over the resulting id set, and this seam must not
 * reintroduce the fan-out they exist to avoid.
 *
 * What comes back is the dashboard's existing client representation,
 * BusinessCandidate, so DashboardStatusReader, the cross-client analytics
 * seams and the presentation rows keep consuming exactly what they already
 * did. `accessible` carries the client Workspace's own active state for
 * PRESENTATION only (contract §18 S-3): opening a client re-authorizes
 * through the canonical Agency View As path, which re-reads the relationship,
 * the Agency's authority and eligibility, and the client's own state.
 */
final class AgencyClientPortfolio
{
    public function __construct(private readonly AgencyClientWorkspaceRelationshipRepository $relationships)
    {
    }

    /**
     * The Businesses of every Client Workspace this Agency Workspace actively
     * manages, oldest relationship first.
     *
     * @return array<int, BusinessCandidate>
     */
    public function activeClientsFor(int $agencyWorkspaceId): array
    {
        $clientWorkspaceIds = $this->relationships
            ->activeForAgencyWorkspace($agencyWorkspaceId)
            ->map(fn (AgencyClientWorkspaceRelationship $relationship) => (int) $relationship->client_workspace_id)
            // A self-link is impossible through Contract 01's manager, and
            // View As refuses one too; rejected here as well so a legacy or
            // migrated row could never list the Agency as its own client.
            ->reject(fn (int $clientWorkspaceId) => $clientWorkspaceId === $agencyWorkspaceId)
            ->unique()
            ->values()
            ->all();

        if ($clientWorkspaceIds === []) {
            return [];
        }

        $rows = DB::table('businesses as b')
            ->join('workspaces as w', 'w.id', '=', 'b.workspace_id')
            ->whereIn('b.workspace_id', $clientWorkspaceIds)
            ->orderBy('b.workspace_id')
            ->orderBy('b.id')
            ->get([
                'b.id as business_id',
                'b.uid as business_uid',
                'b.name as business_name',
                'b.status as business_status',
                'b.customer_id',
                'b.is_primary',
                'w.id as workspace_id',
                'w.uid as workspace_uid',
                'w.name as workspace_name',
                'w.is_active as workspace_active',
            ]);

        $byWorkspace = [];

        foreach ($rows as $row) {
            $byWorkspace[(int) $row->workspace_id][] = $row;
        }

        $clients = [];

        // Relationship order, not query order: the portfolio lists what this
        // Agency took on, in the order it took it on.
        foreach ($clientWorkspaceIds as $workspaceId) {
            $businesses = $byWorkspace[$workspaceId] ?? [];

            if (count($businesses) !== 1) {
                // Zero Businesses, or several: not a V1 client shape. Omitted.
                continue;
            }

            $row = $businesses[0];

            $clients[] = new BusinessCandidate(
                id: (int) $row->business_id,
                uid: (string) $row->business_uid,
                name: (string) $row->business_name,
                status: (string) $row->business_status,
                customerId: (int) $row->customer_id,
                isPrimary: (bool) $row->is_primary,
                accessible: (bool) $row->workspace_active,
                workspaceUid: (string) $row->workspace_uid,
                workspaceName: (string) $row->workspace_name,
            );
        }

        return $clients;
    }
}
