<?php

namespace App\Http\Controllers\Customer\Agency\Concerns;

use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * A real Agency Workspace the actor holds Agency authority over, that is currently an eligible Agency. 404 for
 * every failure — the same non-disclosure the Clients, SaaS and White Label surfaces use. Requires the using
 * class to hold `$workspaces` (WorkspaceRepository) and `$relationships` (AgencyClientRelationshipManager).
 */
trait AuthorizesAgencyWorkspace
{
    private function authorizedAgency(string $workspaceUid): Workspace
    {
        $agency = $this->workspaces->findByUid($workspaceUid) ?? abort(404);

        if (! $this->relationships->actorHasAgencyAuthority((int) Auth::id(), $agency)) {
            abort(404);
        }

        try {
            $this->relationships->assertAgencyWorkspaceHasManagementEligibility($agency);
        } catch (AgencyWorkspaceNotEligibleException) {
            abort(404);
        }

        return $agency;
    }
}
