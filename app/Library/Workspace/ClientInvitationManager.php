<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\ClientInvitationStatus;
use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Models\ClientWorkspaceInvitation;
use App\Models\Workspace;
use App\Notifications\Workspace\ClientInvitationNotification;
use App\Repositories\Contracts\ClientWorkspaceInvitationRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Implementation Contract 07 §5/§6 — the canonical entry point for an
 * Agency's client-workspace invitation lifecycle: send, revoke, and
 * resolving/validating a claim.
 *
 * Creates NO Workspace, Business, Location, User, or Contract 01
 * relationship — only this one durable `client_workspace_invitations` row.
 * AgencyClientProvisioningManager is the only class that ever provisions
 * anything, and only at acceptance time, once a real authenticated
 * User/Customer already exists.
 *
 * AUTHORITY. send()/revoke() call AgencyClientRelationshipManager's own
 * canonical `actorHasAgencyAuthority()` and
 * `agencyWorkspaceHasManagementEligibility()` — the exact same methods
 * Contract 01's create() uses internally — never a duplicate or
 * reimplemented check. There is no separate "manage_agency_clients"
 * permission: ordinary active Admin/Staff membership of the exact Agency
 * Workspace is the whole grant, per Contract 01 §6/Blueprint §2's
 * correction (A1) that provisioning is not owner-only.
 *
 * TOKEN. Never stored or logged in plaintext. A cryptographically strong
 * random token is generated once, hashed with Hash::make() (the same
 * bcrypt-family hasher password_resets already uses) into token_hash, and
 * the plaintext exists only for the lifetime of building the claim email —
 * never written to any column, log line, or exception message.
 */
class ClientInvitationManager
{
    public function __construct(
        private readonly ClientWorkspaceInvitationRepository $invitationRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AgencyClientRelationshipManager $agencyClientRelationshipManager,
    ) {
    }

    /**
     * Send a new client invitation. Creates exactly one Pending row — no
     * Workspace, Business, Location, User, or relationship is touched.
     *
     * §6 deliberately gates SEND on authority alone, not standing Agency
     * eligibility: eligibility is asserted where it actually matters — at
     * ACCEPTANCE, fresh, by Contract 01's own create() (§C/Clarification
     * C) — exactly like an Active relationship's own eligibility is never
     * a standing guarantee (Contract 01 §6). Gating send() on eligibility
     * too would only duplicate that check prematurely against a Workspace
     * state that can still change before acceptance anyway.
     *
     * @throws WorkspaceNotFoundException
     * @throws UnauthorizedAgencyRelationshipManagementException
     */
    public function send(
        int $actorUserId,
        Workspace $agencyWorkspace,
        string $email,
        ?string $intendedBusinessName,
    ): ClientWorkspaceInvitation {
        $plaintextToken = Str::random(64);

        [$invitation, $normalizedEmail, $agencyWorkspaceName] = DB::transaction(function () use ($actorUserId, $agencyWorkspace, $email, $intendedBusinessName, $plaintextToken) {
            $lockedAgencyWorkspace = $this->workspaceRepository->findForUpdate((int) $agencyWorkspace->id);

            if ($lockedAgencyWorkspace === null) {
                throw new WorkspaceNotFoundException((int) $agencyWorkspace->id);
            }

            $this->assertActorMayManageInvitations($actorUserId, $lockedAgencyWorkspace);

            $normalizedEmail = self::normalizeEmail($email);
            $trimmedBusinessName = $intendedBusinessName !== null ? trim($intendedBusinessName) : null;

            $invitation = $this->invitationRepository->create([
                'agency_workspace_id' => $lockedAgencyWorkspace->id,
                'invited_by_user_id' => $actorUserId,
                'email' => $normalizedEmail,
                'token_hash' => Hash::make($plaintextToken),
                'intended_business_name' => $trimmedBusinessName !== '' ? $trimmedBusinessName : null,
                'status' => ClientInvitationStatus::Pending,
                'expires_at' => now()->addDays((int) config('workspace.client_invitation_ttl_days')),
            ]);

            return [$invitation, $normalizedEmail, $lockedAgencyWorkspace->name];
        });

        // Dispatched only after the transaction above has committed: a
        // recipient must never be emailed a claim link for a row that a
        // later failure inside the transaction rolled back.
        Notification::route('mail', $normalizedEmail)->notify(new ClientInvitationNotification(
            $invitation->uid,
            $plaintextToken,
            $agencyWorkspaceName,
            $invitation->intended_business_name,
        ));

        return $invitation;
    }

    /**
     * Revoke a still-Pending invitation. No Workspace side effect — none
     * was ever created. A revoked invitation's claim link becomes unusable
     * immediately (validateClaim() refuses any non-Pending row).
     *
     * @throws WorkspaceNotFoundException
     * @throws UnauthorizedAgencyRelationshipManagementException
     * @throws InvalidClientInvitationClaimException when the invitation is
     *         already Accepted/Expired/Revoked
     */
    public function revoke(int $actorUserId, ClientWorkspaceInvitation $invitation): ClientWorkspaceInvitation
    {
        return DB::transaction(function () use ($actorUserId, $invitation) {
            $locked = $this->invitationRepository->findByUidForUpdate($invitation->uid);

            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel(ClientWorkspaceInvitation::class, [$invitation->id]);
            }

            $agencyWorkspace = $this->workspaceRepository->findForUpdate((int) $locked->agency_workspace_id);

            if ($agencyWorkspace === null) {
                throw new WorkspaceNotFoundException((int) $locked->agency_workspace_id);
            }

            $this->assertActorMayManageInvitations($actorUserId, $agencyWorkspace);

            if ($locked->status !== ClientInvitationStatus::Pending) {
                throw new InvalidClientInvitationClaimException('not_pending');
            }

            return $this->invitationRepository->markRevoked($locked);
        });
    }

    /**
     * The structural half of claim validation — everything that does NOT
     * depend on who, if anyone, is authenticated: the invitation must
     * exist, be Pending, be unexpired, and the plaintext token must match
     * its hash. Used by the claim page's unauthenticated-safe GET, before
     * any sign-in/registration choice is even shown, so an invalid link
     * gets the same generic refusal whether or not anyone is signed in.
     *
     * Read-only — does not lock the row or mutate anything;
     * AgencyClientProvisioningManager::accept() re-validates everything
     * under its own lock immediately before provisioning (§5/§7), so this
     * is never itself the single-use gate.
     *
     * @throws InvalidClientInvitationClaimException
     */
    public function resolveForClaim(string $uid, string $plaintextToken): ClientWorkspaceInvitation
    {
        $invitation = $this->invitationRepository->findByUid($uid);

        if ($invitation === null) {
            throw new InvalidClientInvitationClaimException('not_found');
        }

        if ($invitation->status !== ClientInvitationStatus::Pending) {
            throw new InvalidClientInvitationClaimException('not_pending');
        }

        if ($invitation->isExpired()) {
            throw new InvalidClientInvitationClaimException('expired');
        }

        // Never query by plaintext token — token_hash is a Hash::make()
        // value, not a lookup key. The uid alone locates the row; the
        // plaintext is only ever compared via Hash::check().
        if (! Hash::check($plaintextToken, $invitation->token_hash)) {
            throw new InvalidClientInvitationClaimException('invalid_token');
        }

        return $invitation;
    }

    /**
     * The full claim validation: resolveForClaim()'s structural checks,
     * plus the authenticated actor's normalized email matching the
     * invitation's (§6 — the only actor who may ever complete acceptance).
     *
     * ONE exception type for every failure reason, on purpose (§6): the
     * caller must render one generic "this invitation is no longer valid"
     * response whichever reason applied, never disclosing which.
     *
     * @throws InvalidClientInvitationClaimException
     */
    public function validateClaim(string $uid, string $plaintextToken, string $authenticatedEmail): ClientWorkspaceInvitation
    {
        $invitation = $this->resolveForClaim($uid, $plaintextToken);

        if (self::normalizeEmail($authenticatedEmail) !== $invitation->email) {
            throw new InvalidClientInvitationClaimException('email_mismatch');
        }

        return $invitation;
    }

    /**
     * The one normalization rule every email comparison in this feature
     * uses — this codebase has no existing canonical email normalizer
     * (mechanically confirmed: no normalizeEmail()/EmailNormalizer class,
     * and no email mutator on the User model), so this defines the
     * narrowest safe rule rather than inventing a broader one: trim
     * surrounding whitespace, lowercase (email local-and-domain parts are
     * treated case-insensitively throughout this codebase's existing
     * `users.email` unique lookups), applied identically when the
     * invitation is created and when an accepting actor's email is
     * compared against it.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Contract 01's own canonical Agency authority check, reused verbatim —
     * not duplicated. Ordinary active Admin/Staff membership, or the
     * Workspace owner, of the exact Agency Workspace.
     */
    private function assertActorMayManageInvitations(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ($this->agencyClientRelationshipManager->actorHasAgencyAuthority($actorUserId, $agencyWorkspace)) {
            return;
        }

        throw new UnauthorizedAgencyRelationshipManagementException($actorUserId, (int) $agencyWorkspace->id);
    }
}
