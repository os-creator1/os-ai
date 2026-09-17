<?php

namespace App\Http\Controllers;

use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Library\Workspace\AgencyClientProvisioningManager;
use App\Library\Workspace\ClientInvitationManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 07 §5/§12 — thin claim/accept wiring for the
 * recipient side of a client invitation. No new auth system: an
 * unauthenticated visitor is sent to this codebase's own existing
 * login/register entry points (routes/auth.php), and simply returns to
 * this same, safe, idempotent GET link once they have signed in or
 * registered — nothing here or in RegisterController/LoginController is
 * modified to "resume" the flow automatically, since that would require
 * touching those files, which Contract 07 §12 forbids absent an evidenced
 * contradiction.
 *
 * Every actual decision — token hashing, expiry, status, email match,
 * atomic provisioning — lives in ClientInvitationManager and
 * AgencyClientProvisioningManager. This controller only renders the
 * generic outcomes those two throw/return.
 */
class ClientInvitationClaimController extends Controller
{
    public function __construct(
        private readonly ClientInvitationManager $invitationManager,
        private readonly AgencyClientProvisioningManager $provisioningManager,
    ) {
    }

    /**
     * GET /client-invitations/{uid}/{token} — safe, side-effect-free, and
     * revisitable any number of times.
     *
     * Structural validity (exists, Pending, unexpired, correct token) is
     * checked before authentication is even considered, so an invalid
     * link gets the same generic refusal whether or not anyone is signed
     * in — no account-existence disclosure either way (§5).
     */
    public function show(string $uid, string $token): View
    {
        try {
            $this->invitationManager->resolveForClaim($uid, $token);
        } catch (InvalidClientInvitationClaimException) {
            return view('client_invitations.invalid');
        }

        if (! Auth::check()) {
            return view('client_invitations.sign_in_required');
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            $this->invitationManager->validateClaim($uid, $token, $user->email);
        } catch (InvalidClientInvitationClaimException) {
            // Deliberately the SAME generic view an invalid/expired/revoked
            // link renders — an authenticated-but-wrong-email actor learns
            // nothing distinguishing their case from any other refusal.
            return view('client_invitations.invalid');
        }

        return view('client_invitations.confirm', ['uid' => $uid, 'token' => $token]);
    }

    /**
     * POST /client-invitations/{uid}/{token}/accept — the one place
     * provisioning actually happens. Requires authentication (route
     * middleware); AgencyClientProvisioningManager re-validates everything
     * itself, under its own lock, regardless of what show() already
     * displayed.
     */
    public function accept(string $uid, string $token): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        try {
            $invitation = $this->provisioningManager->accept($user, $uid, $token);
        } catch (InvalidClientInvitationClaimException) {
            return redirect()->route('client-invitations.claim', ['uid' => $uid, 'token' => $token]);
        }

        $workspace = $invitation->createdClientWorkspace;

        return redirect()
            ->route('customer.workspaces.show', $workspace->uid)
            ->with(['status' => 'success', 'message' => 'Workspace created.']);
    }
}
