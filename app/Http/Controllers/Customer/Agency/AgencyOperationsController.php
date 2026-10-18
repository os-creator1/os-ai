<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Http\Controllers\Controller;
use App\Library\Workspace\AgencyOperationsManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Turns on the Agency's own operating workspace (its own CRM, Calendar,
 * Automations, SEO and Ads — see AgencyOperationsManager). A thin layer:
 * every authorization decision is the manager's, and every refusal is a 404 so
 * the existence of someone else's Workspace is never disclosed. Nothing here is
 * reachable through View As (customer.workspaces.agency.operations. is a
 * ViewAsProhibitedActions prefix).
 */
class AgencyOperationsController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyOperationsManager $operations,
    ) {
    }

    public function enable(string $workspaceUid): RedirectResponse
    {
        $agency = $this->workspaces->findByUid($workspaceUid) ?? abort(404);

        try {
            $this->operations->enable((int) Auth::id(), $agency);
        } catch (UnauthorizedWorkspaceManagementException|AgencyWorkspaceNotEligibleException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->route('user.home')->with(['status' => 'error', 'message' => 'This account is inactive.']);
        }

        return redirect()->route('user.home')->with(['status' => 'success', 'message' => 'Your agency workspace is ready.']);
    }
}
