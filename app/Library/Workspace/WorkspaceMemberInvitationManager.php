<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMemberInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidMemberInvitationException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\PendingMemberInvitationExistsException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMemberInvitation;
use App\Notifications\Workspace\WorkspaceMemberInvitationNotification;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Settings → Team invitations for a person who has no MotionGrove account yet.
 *
 * Creating an invitation writes ONE row and sends ONE email. It creates no
 * User, Customer, Workspace, Business, subscription or trial. Membership is
 * only ever granted through WorkspaceManager::addMember() — the single
 * authority over roles, Business access and owner/duplicate rules — acting as
 * the original inviter, so the inviter's authority is re-checked at the moment
 * of acceptance (an inviter who has since lost it makes the invitation fail).
 *
 * TOKEN: 64 random characters, stored only as Hash::make(), lookup by uid,
 * never logged or returned; resending replaces it (the old link dies).
 */
class WorkspaceMemberInvitationManager
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly WorkspaceMembershipRepository $membershipRepository,
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Whether this actor may grant exactly this role and Business access right now: Admin needs the owner; Staff needs
     * the owner or an active Admin; `all` needs an unrestricted actor; selected Businesses must each be reachable by
     * the actor. The same rules WorkspaceController::storeMember() applies, asked again here and at acceptance.
     *
     * @param  array<int, int>  $businessIds
     */
    public function actorMayGrant(int $actorUserId, Workspace $workspace, WorkspaceMembershipRole $role, WorkspaceBusinessAccessScope $scope, array $businessIds): bool
    {
        $isOwner = (int) $workspace->owner_user_id === $actorUserId;
        $membership = $isOwner ? null : $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);
        $isAdmin = $membership !== null && $membership->is_active && $membership->role === WorkspaceMembershipRole::Admin;

        if (! $isOwner && ! $isAdmin) {
            return false;
        }

        if ($role === WorkspaceMembershipRole::Admin && ! $isOwner) {
            return false;
        }

        if ($scope === WorkspaceBusinessAccessScope::All) {
            return $businessIds === [] && ($isOwner || $membership->business_access_scope === WorkspaceBusinessAccessScope::All);
        }

        if ($businessIds === []) {
            return false;
        }

        $businesses = Business::query()->where('workspace_id', $workspace->id)->whereIn('id', $businessIds)->get();

        if ($businesses->count() !== count(array_unique($businessIds))) {
            return false;
        }

        foreach ($businesses as $business) {
            if (! $this->workspaceManager->userCanAccessBusiness($actorUserId, $business)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $businessIds
     *
     * @throws UnauthorizedWorkspaceManagementException
     * @throws InactiveWorkspaceMutationException
     * @throws OwnerCannotBeMemberException
     * @throws WorkspaceMembershipAlreadyExistsException
     * @throws PendingMemberInvitationExistsException
     */
    public function invite(int $actorUserId, Workspace $workspace, string $email, WorkspaceMembershipRole $role, WorkspaceBusinessAccessScope $scope, array $businessIds): WorkspaceMemberInvitation
    {
        $plaintext = Str::random(64);
        $normalized = self::normalizeEmail($email);

        $invitation = DB::transaction(function () use ($actorUserId, $workspace, $normalized, $role, $scope, $businessIds, $plaintext) {
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new InactiveWorkspaceMutationException((int) $locked->id);
            }

            $businessIds = array_values(array_unique(array_map('intval', $businessIds)));

            if (! $this->actorMayGrant($actorUserId, $locked, $role, $scope, $businessIds)) {
                throw new UnauthorizedWorkspaceManagementException($actorUserId, (int) $locked->id);
            }

            $existingUser = User::query()->where('email', $normalized)->first();

            if ($existingUser !== null) {
                // An address that already has an account is added directly, never invited (see WorkspaceController).
                if ((int) $existingUser->id === (int) $locked->owner_user_id) {
                    throw new OwnerCannotBeMemberException((int) $locked->id, (int) $existingUser->id);
                }

                throw new WorkspaceMembershipAlreadyExistsException((int) $locked->id, (int) $existingUser->id, true);
            }

            // Hashed so the unique key is short and fixed-length whatever the address length (the column is varchar(191)).
            $pendingKey = $locked->id . '|' . sha1($normalized);
            $current = WorkspaceMemberInvitation::query()->where('pending_key', $pendingKey)->lockForUpdate()->first();

            if ($current !== null) {
                if (! $current->isExpired()) {
                    throw new PendingMemberInvitationExistsException();
                }

                $this->close($current, WorkspaceMemberInvitationStatus::Expired);
            }

            return WorkspaceMemberInvitation::create([
                'workspace_id' => $locked->id,
                'invited_by_user_id' => $actorUserId,
                'email' => $normalized,
                'role' => $role,
                'business_access_scope' => $scope,
                'business_ids' => $scope === WorkspaceBusinessAccessScope::Selected ? $businessIds : null,
                'token_hash' => Hash::make($plaintext),
                'status' => WorkspaceMemberInvitationStatus::Pending,
                'pending_key' => $pendingKey,
                'expires_at' => $this->newExpiry(),
                'last_sent_at' => now(),
            ]);
        });

        // After commit: nobody is emailed a link for a row that rolled back.
        $this->send($invitation, $plaintext);

        return $invitation;
    }

    /**
     * Issue a fresh link (the previous one stops working) and restart the 7-day window.
     *
     * @throws UnauthorizedWorkspaceManagementException
     * @throws InvalidMemberInvitationException when the invitation is no longer pending
     */
    public function resend(int $actorUserId, WorkspaceMemberInvitation $invitation): WorkspaceMemberInvitation
    {
        $plaintext = Str::random(64);

        $updated = DB::transaction(function () use ($actorUserId, $invitation, $plaintext) {
            $locked = WorkspaceMemberInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertActorMayManage($actorUserId, $locked);

            if (! $locked->isPending()) {
                throw new InvalidMemberInvitationException('not_pending');
            }

            $locked->forceFill([
                'token_hash' => Hash::make($plaintext),
                'expires_at' => $this->newExpiry(),
                'last_sent_at' => now(),
            ])->save();

            return $locked;
        });

        $this->send($updated, $plaintext);

        return $updated;
    }

    /**
     * @throws UnauthorizedWorkspaceManagementException
     * @throws InvalidMemberInvitationException
     */
    public function revoke(int $actorUserId, WorkspaceMemberInvitation $invitation): WorkspaceMemberInvitation
    {
        return DB::transaction(function () use ($actorUserId, $invitation) {
            $locked = WorkspaceMemberInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertActorMayManage($actorUserId, $locked);

            if (! $locked->isPending()) {
                throw new InvalidMemberInvitationException('not_pending');
            }

            return $this->close($locked, WorkspaceMemberInvitationStatus::Revoked);
        });
    }

    /**
     * Structural validity, independent of who is signed in: exists, pending, unexpired, token matches.
     *
     * @throws InvalidMemberInvitationException
     */
    public function resolveForClaim(string $uid, string $plaintextToken): WorkspaceMemberInvitation
    {
        $invitation = WorkspaceMemberInvitation::query()->where('uid', $uid)->first();

        if ($invitation === null) {
            throw new InvalidMemberInvitationException('not_found');
        }

        if (! $invitation->isPending()) {
            throw new InvalidMemberInvitationException('not_pending');
        }

        if ($invitation->isExpired()) {
            throw new InvalidMemberInvitationException('expired');
        }

        if (! Hash::check($plaintextToken, $invitation->token_hash)) {
            throw new InvalidMemberInvitationException('invalid_token');
        }

        return $invitation;
    }

    /** @throws InvalidMemberInvitationException */
    public function validateClaim(string $uid, string $plaintextToken, string $authenticatedEmail): WorkspaceMemberInvitation
    {
        $invitation = $this->resolveForClaim($uid, $plaintextToken);

        if (self::normalizeEmail($authenticatedEmail) !== $invitation->email) {
            throw new InvalidMemberInvitationException('email_mismatch');
        }

        return $invitation;
    }

    /**
     * A signed-in person whose account email is the invited one accepts.
     *
     * @throws InvalidMemberInvitationException
     */
    public function acceptAsUser(User $user, string $uid, string $plaintextToken): WorkspaceMembership
    {
        return $this->accept($uid, $plaintextToken, function (WorkspaceMemberInvitation $invitation) use ($user): User {
            if (self::normalizeEmail((string) $user->email) !== $invitation->email) {
                throw new InvalidMemberInvitationException('email_mismatch');
            }

            return $user;
        });
    }

    /**
     * A person with no account sets a password. The emailed single-use link proves they control the invited address,
     * so the account is created already verified, and ONLY the invited membership is granted: no Workspace, Business,
     * subscription or trial is created.
     *
     * @param  array{first_name: string, last_name?: ?string, password: string}  $profile
     *
     * @throws InvalidMemberInvitationException ('account_exists' when the address already has an account: sign in instead)
     */
    public function acceptAsNewUser(string $uid, string $plaintextToken, array $profile): User
    {
        $created = null;

        $this->accept($uid, $plaintextToken, function (WorkspaceMemberInvitation $invitation) use ($profile, &$created): User {
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw new InvalidMemberInvitationException('account_exists');
            }

            $created = $this->createUser($invitation->email, $profile);

            return $created;
        });

        return $created;
    }

    /**
     * @param  callable(WorkspaceMemberInvitation): User  $resolveUser  runs inside the transaction, AFTER the grant is known
     *         to be allowed, so a refused invitation never leaves a half-made account behind
     */
    private function accept(string $uid, string $plaintextToken, callable $resolveUser): WorkspaceMembership
    {
        $deadInvitationId = null;

        try {
            return DB::transaction(function () use ($uid, $plaintextToken, $resolveUser, &$deadInvitationId) {
                $invitation = WorkspaceMemberInvitation::query()->where('uid', $uid)->lockForUpdate()->first();

                if ($invitation === null || ! $invitation->isPending() || $invitation->isExpired() || ! Hash::check($plaintextToken, $invitation->token_hash)) {
                    throw new InvalidMemberInvitationException('not_usable');
                }

                $workspace = Workspace::query()->whereKey($invitation->workspace_id)->lockForUpdate()->first();
                $businessIds = array_map('intval', $invitation->business_ids ?? []);
                $deadInvitationId = $invitation->id;

                // Re-validated NOW against the inviter's current authority, role and Business scope.
                if ($workspace === null || ! $workspace->is_active
                    || ! $this->actorMayGrant((int) $invitation->invited_by_user_id, $workspace, $invitation->role, $invitation->business_access_scope, $businessIds)) {
                    throw new InvalidMemberInvitationException('grant_failed');
                }

                $user = $resolveUser($invitation);

                try {
                    $membership = $this->workspaceManager->addMember(
                        (int) $invitation->invited_by_user_id,
                        $workspace,
                        (int) $user->id,
                        $invitation->role,
                        $invitation->business_access_scope,
                        LocationAccessScope::All,
                        $businessIds,
                    );
                } catch (Throwable) {
                    throw new InvalidMemberInvitationException('grant_failed');
                }

                $deadInvitationId = null;
                $invitation->forceFill([
                    'status' => WorkspaceMemberInvitationStatus::Accepted,
                    'pending_key' => null,
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->id,
                ])->save();

                return $membership;
            });
        } catch (InvalidMemberInvitationException $e) {
            // A grant that can no longer be made kills the invitation (everything above has been rolled back,
            // including any account being created); every other refusal leaves it exactly as it was.
            if ($e->reason === 'grant_failed' && $deadInvitationId !== null) {
                DB::transaction(function () use ($deadInvitationId) {
                    $invitation = WorkspaceMemberInvitation::query()->whereKey($deadInvitationId)->lockForUpdate()->first();

                    if ($invitation !== null && $invitation->isPending()) {
                        $this->close($invitation, WorkspaceMemberInvitationStatus::Revoked);
                    }
                });
            }

            throw $e;
        }
    }

    /** @param  array{first_name: string, last_name?: ?string, password: string}  $profile */
    private function createUser(string $email, array $profile): User
    {
        $user = new User([
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'] ?? null,
            'email' => $email,
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'locale' => config('app.locale'),
            'timezone' => config('app.timezone'),
            'email_verified_at' => now(),
        ]);
        $user->password = Hash::make($profile['password']);
        $user->save();

        Customer::create([
            'user_id' => $user->id,
            'phone' => null,
            'permissions' => Customer::customerPermissions(),
            'notifications' => json_encode([
                'login' => 'no', 'tickets' => 'yes', 'sender_id' => 'yes', 'keyword' => 'yes',
                'subscription' => 'yes', 'promotion' => 'yes', 'profile' => 'yes',
            ]),
        ]);

        return $user;
    }

    private function assertActorMayManage(int $actorUserId, WorkspaceMemberInvitation $invitation): void
    {
        $workspace = Workspace::query()->findOrFail($invitation->workspace_id);
        $isOwner = (int) $workspace->owner_user_id === $actorUserId;
        $membership = $isOwner ? null : $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);
        $isAdmin = $membership !== null && $membership->is_active && $membership->role === WorkspaceMembershipRole::Admin;

        // Owner: any invitation. Active Admin: Staff invitations only (Admin invitations stay owner-only).
        if (! $isOwner && ! ($isAdmin && $invitation->role === WorkspaceMembershipRole::Staff)) {
            throw new UnauthorizedWorkspaceManagementException($actorUserId, (int) $workspace->id);
        }
    }

    private function close(WorkspaceMemberInvitation $invitation, WorkspaceMemberInvitationStatus $status): WorkspaceMemberInvitation
    {
        $invitation->forceFill([
            'status' => $status,
            'pending_key' => null,
            'revoked_at' => $status === WorkspaceMemberInvitationStatus::Revoked ? now() : $invitation->revoked_at,
        ])->save();

        return $invitation;
    }

    private function newExpiry(): \Illuminate\Support\Carbon
    {
        return now()->addDays((int) config('workspace.member_invitation_ttl_days', 7));
    }

    private function send(WorkspaceMemberInvitation $invitation, string $plaintext): void
    {
        $workspace = Workspace::query()->find($invitation->workspace_id);

        Notification::route('mail', $invitation->email)->notify(new WorkspaceMemberInvitationNotification(
            $invitation->uid,
            $plaintext,
            (string) ($workspace?->name ?? 'a MotionGrove account'),
            ucfirst($invitation->role->value),
            $invitation->expires_at,
        ));
    }
}
