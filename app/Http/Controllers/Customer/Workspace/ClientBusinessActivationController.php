<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ActivateClientBusinessRequest;
use App\Library\Business\BusinessManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Fix for the newly-invited-client flow (Contract 07 correction): invitation
 * acceptance (AgencyClientProvisioningManager::accept()) creates the Client
 * Business as Draft with placeholder identity (industry Other, country US,
 * timezone UTC, currency USD) and an address-less storefront primary
 * location, and deliberately never activates it. Agency "View As" requires
 * Active (ViewAsManager::startAgencyView()), so a Draft Business was
 * previously simply unreachable from the client side — nothing here ever
 * activated it.
 *
 * THIS is that missing step: the client owner's own, explicit review and
 * confirmation of the exact facts accept() placeholdered, before the
 * Business can ever become Active. Every write goes through
 * BusinessManager::activateClientBusiness(), which re-verifies ownership
 * and the Draft status under the Business row lock and performs the
 * identity write, the primary-location write and the Draft -> Active
 * transition atomically.
 *
 * OWNER-ONLY, BY CONSTRUCTION. Both actions resolve the target Workspace
 * through resolveOwnedWorkspace() below, which accepts only the Workspace's
 * own owner_user_id — never an Admin/Staff member, and never the inviting
 * Agency (whose actor id is never this Workspace's owner_user_id: the
 * Workspace was created for the accepting client,
 * WorkspaceManager::createWorkspace((int) $authenticatedUser->id, ...), in
 * AgencyClientProvisioningManager::accept()). An Agency actor reaching
 * either route gets the identical 404 an unrelated stranger would.
 */
class ClientBusinessActivationController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessManager $businessManager,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, (int) Auth::id());
        $business = $this->resolveWorkspaceBusiness($workspace, $businessUid);

        if ($business->status !== BusinessStatus::Draft) {
            return redirect()
                ->route('customer.workspaces.show', $workspace->uid)
                ->with('flash_success', 'This Business is already set up.');
        }

        return view('customer.workspaces.client-business-activation', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $business->primaryLocation,
        ]);
    }

    public function store(ActivateClientBusinessRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $customer = $this->customer();
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, (int) Auth::id());
        $business = $this->resolveWorkspaceBusiness($workspace, $businessUid);

        try {
            $this->businessManager->activateClientBusiness(
                $customer,
                $business,
                $request->identityAttributes(),
                $request->locationAttributes(),
            );
        } catch (WorkspaceAccessDeniedException) {
            abort(404);
        } catch (RuntimeException) {
            return redirect()
                ->route('customer.workspaces.show', $workspace->uid)
                ->with('flash_success', 'This Business is already set up.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspace->uid)
            ->with('flash_success', 'Your Business is now active.');
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
    }

    /**
     * Resolves a Workspace this exact authenticated user OWNS — never an
     * Admin/Staff member, and never the Agency that invited them. A
     * missing, inactive, or not-owned Workspace all fail closed with the
     * same 404 (contract §5.4 existence-disclosure rule).
     */
    private function resolveOwnedWorkspace(string $workspaceUid, int $userId): Workspace
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active || (int) $workspace->owner_user_id !== $userId) {
            abort(404);
        }

        return $workspace;
    }

    /**
     * Resolves the Business by opaque uid, scoped to this Workspace. A uid
     * that does not exist, or belongs to a different Workspace, both fail
     * closed identically with 404.
     */
    private function resolveWorkspaceBusiness(Workspace $workspace, string $businessUid): Business
    {
        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null) {
            abort(404);
        }

        return $business;
    }
}
