<?php

namespace App\Http\Controllers;

use App\Exceptions\Workspace\InvalidMemberInvitationException;
use App\Library\Navigation\SoleBusinessLanding;
use App\Library\Workspace\ClientInvitationReturn;
use App\Library\Workspace\WorkspaceMemberInvitationManager;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMemberInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The recipient side of a Settings → Team invitation. No new auth system: a new
 * person sets a password here (the emailed single-use link proves they own the
 * address), an existing person signs in through the normal login and confirms.
 *
 * Every refusal — unknown, expired, revoked, used, wrong token, wrong signed-in
 * account, inviter no longer authorised — renders the SAME generic view, so
 * nothing about any account or address is disclosed. The token is never logged
 * and never echoed back; the only thing it appears in is the link's own URL.
 */
class WorkspaceMemberInvitationClaimController extends Controller
{
    public function __construct(private readonly WorkspaceMemberInvitationManager $invitations, private readonly SoleBusinessLanding $landing)
    {
    }

    /** GET — side-effect-free and revisitable. */
    public function show(string $uid, string $token): View
    {
        try {
            $invitation = $this->invitations->resolveForClaim($uid, $token);
        } catch (InvalidMemberInvitationException $e) {
            return $this->invalid('show', $uid, $e);
        }

        $accountName = (string) (Workspace::query()->whereKey($invitation->workspace_id)->value('name') ?? 'a MotionGrove account');
        $summary = ['account' => $accountName, 'role' => ucfirst($invitation->role->value)];

        if (! Auth::check()) {
            // One page for everyone: set a password, or sign in. It reveals nothing about whether the address has an account.
            ClientInvitationReturn::rememberMember($uid, $token);

            return view('workspace_invitations.welcome', $summary + ['uid' => $uid, 'token' => $token, 'email' => $invitation->email]);
        }

        ClientInvitationReturn::forgetMember();

        try {
            $this->invitations->validateClaim($uid, $token, (string) Auth::user()->email);
        } catch (InvalidMemberInvitationException $e) {
            return $this->invalid('show_authenticated', $uid, $e);
        }

        return view('workspace_invitations.confirm', $summary + ['uid' => $uid, 'token' => $token]);
    }

    /** POST — a new person chooses a password and joins. Signs them in. */
    public function register(Request $request, string $uid, string $token): RedirectResponse|View
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = $this->invitations->acceptAsNewUser($uid, $token, $data);
        } catch (InvalidMemberInvitationException $e) {
            if ($e->reason === 'account_exists') {
                // The link holder is the invited address: send them to sign in, which is the existing-user path.
                ClientInvitationReturn::rememberMember($uid, $token);

                return redirect()->route('login')->with(['status' => 'info', 'message' => 'Sign in to accept your MotionGrove invitation.']);
            }

            return $this->invalid('register', $uid, $e);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return $this->joined((int) $user->id, $uid);
    }

    /** POST — a signed-in person with the invited email confirms. */
    public function accept(Request $request, string $uid, string $token): RedirectResponse|View
    {
        /** @var User $user */
        $user = Auth::user();

        try {
            $this->invitations->acceptAsUser($user, $uid, $token);
        } catch (InvalidMemberInvitationException $e) {
            return $this->invalid('accept', $uid, $e);
        }

        return $this->joined((int) $user->id, $uid);
    }

    private function joined(int $userId, string $invitationUid): RedirectResponse
    {
        // The Business Home is where a team member works. The account overview is not for selected-scope staff,
        // so a single authorized Business is opened directly; with several, the normal context resolver chooses
        // among the ones this person may legitimately open — never an unrelated one.
        // Target the account this invitation joined, so a person who already has another Business is not left on it.
        // Access is re-checked against the finalized membership; the invitation itself grants no navigation.
        $workspaceId = WorkspaceMemberInvitation::query()->where('uid', $invitationUid)->value('workspace_id');
        $workspaceUid = $workspaceId !== null ? Workspace::query()->whereKey($workspaceId)->value('uid') : null;
        $this->landing->rememberSole($userId, $workspaceUid);

        return redirect()
            ->route('user.home')
            ->with(['status' => 'success', 'message' => 'Welcome to the team.']);
    }

    private function invalid(string $stage, string $uid, InvalidMemberInvitationException $e): View
    {
        // Internal diagnostics only: the reason keyword and the invitation uid (which cannot be used to claim).
        Log::info('Workspace member invitation refused', ['stage' => $stage, 'invitation_uid' => $uid, 'reason' => $e->reason]);

        return view('workspace_invitations.invalid');
    }
}
