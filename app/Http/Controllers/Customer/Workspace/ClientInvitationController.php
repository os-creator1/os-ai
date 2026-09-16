<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Http\Controllers\Controller;
use App\Library\Workspace\ClientInvitationManager;
use App\Repositories\Contracts\ClientWorkspaceInvitationRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 07 §6/§12 — thin send/revoke wiring for the
 * Agency side of client invitations. No Clients UI is built here (Contract
 * 08A); this controller exists only so send()/revoke() are reachable, and
 * every actual decision (authority, eligibility, token generation) lives in
 * ClientInvitationManager.
 */
class ClientInvitationController extends Controller
{
    public function __construct(
        private readonly ClientInvitationManager $invitationManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly ClientWorkspaceInvitationRepository $invitationRepository,
    ) {
    }

    public function store(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'intended_business_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->invitationManager->send(
                (int) Auth::id(),
                $workspace,
                $data['email'],
                $data['intended_business_name'] ?? null,
            );
        } catch (UnauthorizedAgencyRelationshipManagementException) {
            abort(403);
        }

        return back()->with([
            'status' => 'success',
            'message' => 'Invitation sent.',
        ]);
    }

    public function revoke(Request $request, string $workspaceUid, string $invitationUid): RedirectResponse
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $invitation = $this->invitationRepository->findByUid($invitationUid);

        if ($invitation === null || (int) $invitation->agency_workspace_id !== (int) $workspace->id) {
            abort(404);
        }

        try {
            $this->invitationManager->revoke((int) Auth::id(), $invitation);
        } catch (UnauthorizedAgencyRelationshipManagementException) {
            abort(403);
        } catch (InvalidClientInvitationClaimException) {
            return back()->with([
                'status' => 'error',
                'message' => 'This invitation can no longer be revoked.',
            ]);
        }

        return back()->with([
            'status' => 'success',
            'message' => 'Invitation revoked.',
        ]);
    }
}
