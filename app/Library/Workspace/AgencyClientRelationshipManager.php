<?php

namespace App\Library\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\AgencyClientRelationshipEstablished;
use App\Events\Workspace\AgencyClientRelationshipTerminated;
use App\Exceptions\Workspace\AgencyClientSelfLinkException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\ClientWorkspaceAlreadyManagedException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one domain entry point for the canonical Agency -> Client Workspace
 * management relationship (V1 Architecture Decision Addendum §2,
 * Implementation Contract 01).
 *
 * WHAT THIS IS NOT. Nothing here grants access to anything by itself. This
 * class records the link and its lifecycle, and exposes the ONE definition
 * of ordinary Agency-side authority (actorHasAgencyAuthority()) and of Agency
 * management eligibility (agencyWorkspaceHasManagementEligibility()) that
 * every Agency product action — establishing a relationship here, Contract
 * 04's View As — asks. An Active row is never proof that the Agency Workspace
 * is still eligible (Contract 01 §6): eligibility is asserted when the
 * relationship is established, a later downgrade or lock leaves the row
 * untouched, and each consuming capability re-asks it every time it acts.
 *
 * TWO WAYS IN, ONE RELATIONSHIP. create() is the product action an Agency
 * takes; createForMigration() is the operator-run primitive Contract 10's
 * legacy migration uses to record the REAL human operator who ran it. They
 * differ only in who may act. Every structural rule — self-link refusal, both
 * Workspace locks, the Agency eligibility gate, the locking duplicate read,
 * the row write and its event — lives once, in establish(), so the two can never
 * drift into two different relationship implementations.
 *
 * Agency authority is never inferred from Client Workspace membership
 * (Addendum §2): the Agency-side decisions read the AGENCY Workspace's owner
 * and membership rows only, and the platform-side decisions read only the
 * acting User's own admin flag and admin Role permissions.
 */
class AgencyClientRelationshipManager
{
    /**
     * The admin-side Role permission a platform actor must hold, alongside
     * is_admin, for the two acts reserved to the platform: terminating a
     * relationship on the platform's behalf, and establishing one through
     * the operator-run migration primitive. Registered in
     * config/permissions.php — the ADMIN registry — and read directly from
     * the acting account's admin Role permissions (Role/Permission/RoleUser),
     * never through the generic mixed-account Gate; see
     * isPlatformRelationshipOperator().
     */
    public const PLATFORM_RELATIONSHIP_PERMISSION = 'manage agency relationships';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly EntitlementManager $entitlementManager,
        // Contract 03/05's one authority for "is this account usable right
        // now" — read, never re-derived here from lifecycle columns.
        private readonly CustomerAccountAccessResolver $accountAccessResolver,
    ) {
    }

    /**
     * The product action: $agencyWorkspace begins managing $clientWorkspace,
     * at the request of that Agency's own owner or an active Agency Admin or
     * Staff member (actorHasAgencyAuthority()), while the Agency holds
     * management eligibility (agencyWorkspaceHasManagementEligibility()).
     *
     * Authorized ONLY through Agency-side authority. Platform status grants
     * nothing here: is_admin and admin Role permissions — including
     * PLATFORM_RELATIONSHIP_PERMISSION — add zero authority to this path, so
     * the platform never originates a management relationship on a
     * customer's behalf (Addendum §10's posture, applied to creation). One
     * global User can, however, legitimately be both a platform account and
     * the real owner or an active member of an Agency Workspace; being an
     * admin does not erase that Agency-side authority, so such a User may
     * create exactly when they independently qualify on the Agency side. The
     * operator-run legacy migration is the one sanctioned platform-originated
     * exception, and it has its own entry point, createForMigration(), so this
     * rule never has to bend for it.
     *
     * Deliberately NOT idempotent: this is a single human-initiated action,
     * not a retried side effect, so a second attempt on an already-managed
     * Client Workspace throws rather than silently succeeding.
     *
     * @throws AgencyClientSelfLinkException
     * @throws WorkspaceNotFoundException
     * @throws UnauthorizedAgencyRelationshipManagementException
     * @throws AgencyWorkspaceNotEligibleException
     * @throws ClientWorkspaceAlreadyManagedException
     */
    public function create(
        int $actorUserId,
        Workspace $agencyWorkspace,
        Workspace $clientWorkspace,
    ): AgencyClientWorkspaceRelationship {
        return $this->establish(
            $actorUserId,
            $agencyWorkspace,
            $clientWorkspace,
            fn (Workspace $lockedAgencyWorkspace) => $this->assertActorMayManageAgencyRelationships($actorUserId, $lockedAgencyWorkspace),
        );
    }

    /**
     * The operator-run migration primitive (Contract 01 §9, consumed by
     * Contract 10's per-Business sequence, step 4): establishes the same
     * relationship create() would, but on the authority of the human operator
     * running the legacy Agency data migration — and records THAT operator as
     * established_by_user_id and as the event's actor. The Agency owner did
     * not take this action, so it is never attributed to them.
     *
     * Not a product action, and deliberately named so it cannot be mistaken
     * for one: no route, controller or UI reaches it. It is not a wider
     * create(). The operator must be an admin-panel account (is_admin) AND
     * hold the dedicated PLATFORM_RELATIONSHIP_PERMISSION Role permission;
     * an Agency owner or team member — whatever customer permissions they
     * hold — is refused here and uses create() instead.
     *
     * Every structural guarantee is create()'s own, because both run through
     * establish(): self-link refusal, both Workspace row locks in ascending
     * id order, the Agency eligibility gate, the locking duplicate read, the
     * generated-column unique backstop, the same row shape and the same
     * AgencyClientRelationshipEstablished event.
     *
     * @throws AgencyClientSelfLinkException
     * @throws WorkspaceNotFoundException
     * @throws UnauthorizedAgencyRelationshipManagementException
     * @throws AgencyWorkspaceNotEligibleException
     * @throws ClientWorkspaceAlreadyManagedException
     */
    public function createForMigration(
        int $operatorUserId,
        Workspace $agencyWorkspace,
        Workspace $clientWorkspace,
    ): AgencyClientWorkspaceRelationship {
        return $this->establish(
            $operatorUserId,
            $agencyWorkspace,
            $clientWorkspace,
            fn (Workspace $lockedAgencyWorkspace) => $this->assertActorMayOperateRelationshipMigration($operatorUserId, $lockedAgencyWorkspace),
        );
    }

    /**
     * Ends an Agency's management of a Client Workspace.
     *
     * The row is never deleted (Addendum §2): it keeps its own establishment
     * audit and gains the termination one. Terminating an already-terminated
     * relationship is an authorized no-op that returns the row untouched —
     * authority is still asserted first, and the original termination audit
     * is never overwritten — following WorkspaceManager::deactivateWorkspace()'s
     * own duplicate-transition precedent.
     *
     * Termination authority does not depend on how the relationship was
     * established: a relationship the migration created is ended by exactly
     * the same actors as one an Agency created.
     *
     * @throws InvalidArgumentException when no reason is given
     * @throws WorkspaceNotFoundException
     * @throws UnauthorizedAgencyRelationshipManagementException
     */
    public function terminate(
        int $actorUserId,
        AgencyClientWorkspaceRelationship $relationship,
        string $reason,
    ): AgencyClientWorkspaceRelationship {
        $normalizedReason = trim($reason);

        // Mandatory at the application layer whenever terminated_at is set,
        // mirroring business_payer_transitions.reason (Contract 01 §5).
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('A termination reason is required to end an Agency client relationship.');
        }

        return DB::transaction(function () use ($actorUserId, $relationship, $normalizedReason) {
            // Contract 01 §7 fixes this order: the relationship row itself
            // first, then the Agency Workspace row that authorizes the act.
            $locked = $this->relationshipRepository->findForUpdate((int) $relationship->id);

            if ($locked === null) {
                // Unreachable through any path this slice exposes — nothing
                // deletes a relationship row — but a caller could still hand
                // over a stale or never-persisted model, and that must not
                // be mistaken for "already terminated".
                throw (new ModelNotFoundException())
                    ->setModel(AgencyClientWorkspaceRelationship::class, [(int) $relationship->id]);
            }

            $agencyWorkspaceId = (int) $locked->agency_workspace_id;
            $lockedAgencyWorkspace = $this->workspaceRepository->findForUpdate($agencyWorkspaceId);

            if ($lockedAgencyWorkspace === null) {
                throw new WorkspaceNotFoundException($agencyWorkspaceId);
            }

            $this->assertActorMayTerminateAgencyRelationship($actorUserId, $lockedAgencyWorkspace);

            if (! $locked->isActive()) {
                return $locked;
            }

            $terminated = $this->relationshipRepository->markTerminated($locked, $actorUserId, $normalizedReason);

            AgencyClientRelationshipTerminated::dispatch(
                (int) $terminated->id,
                $agencyWorkspaceId,
                (int) $terminated->client_workspace_id,
                $actorUserId,
                $normalizedReason,
            );

            return $terminated;
        }, 3);
    }

    /**
     * The Client Workspace's one active managing Agency, or null.
     *
     * Structural lookup with no actor argument, deliberately. Contract 01
     * §6's Read column is about the SURFACES that will expose this data —
     * the Agency Clients UI and the admin surface — and it states outright
     * that the Client Workspace side "is not this manager's concern". This
     * slice wires no surface at all, so binding a read authority here would
     * invent one the contract assigns to Contract 04/08A's own call sites.
     */
    public function findActiveForClientWorkspace(int $clientWorkspaceId): ?AgencyClientWorkspaceRelationship
    {
        return $this->relationshipRepository->findActiveForClientWorkspace($clientWorkspaceId);
    }

    /**
     * Every Client Workspace this Agency currently manages.
     *
     * @return Collection<int, AgencyClientWorkspaceRelationship>
     */
    public function findActiveForAgencyWorkspace(int $agencyWorkspaceId): Collection
    {
        return $this->relationshipRepository->activeForAgencyWorkspace($agencyWorkspaceId);
    }

    /**
     * Every relationship a Client Workspace has ever had, terminated ones
     * included — the audit read that makes "managed before, by someone else"
     * distinguishable from "never managed".
     *
     * @return Collection<int, AgencyClientWorkspaceRelationship>
     */
    public function historyForClientWorkspace(int $clientWorkspaceId): Collection
    {
        return $this->relationshipRepository->historyForClientWorkspace($clientWorkspaceId);
    }

    /**
     * Read-only: may this actor act for $agencyWorkspace as the Agency, for
     * ORDINARY, non-financial Agency management?
     *
     * THE one definition (Contract 04 §6, "reuse, not duplication"), derived
     * ONLY from $agencyWorkspace's own rows:
     *  - the exact Agency Workspace owner (owner_user_id), or
     *  - an ACTIVE WorkspaceMembership of the actor in exactly this Agency
     *    Workspace, whose role is Admin or Staff.
     *
     * V1 rule (Contract 04 authority correction): active Agency membership IS
     * the ordinary-management grant. There is deliberately no customer
     * permission on top — the customer permission list is User-global, never
     * Workspace-scoped, so it could only leak authority from one Agency
     * Workspace into another (Addendum §3). Nothing here reads the Gate,
     * customers.permissions, session permissions, is_admin, admin Roles,
     * user-id-1 conventions, another Workspace's membership, or Client
     * Workspace membership (Addendum §2).
     *
     * The membership is read through the repository on every call; its
     * memo lives for one request only and every membership write
     * invalidates it, so a deactivated or removed membership is seen by the
     * next request.
     *
     * The owner-only exceptions are NOT this method: relationship
     * termination (assertActorMayTerminateAgencyRelationship()) and every
     * AgencyRebill consent/payer/funding authority (Contract 09) stay
     * owner-only regardless of membership.
     *
     * create() asserts exactly this, and ViewAsManager::startAgencyView() —
     * and its per-read revalidation — ask exactly this.
     */
    public function actorHasAgencyAuthority(int $actorUserId, Workspace $agencyWorkspace): bool
    {
        if ((int) $agencyWorkspace->owner_user_id === $actorUserId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($agencyWorkspace, $actorUserId);

        return $membership !== null
            && (int) $membership->workspace_id === (int) $agencyWorkspace->id
            && (int) $membership->user_id === $actorUserId
            && $membership->is_active
            && in_array($membership->role, [WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff], true);
    }

    /**
     * Read-only: may $agencyWorkspace perform Agency management right now?
     *
     * THE one definition of Agency management eligibility, requiring BOTH:
     *  1. its current plan tier is Agency; and
     *  2. its effective account access is usable, as decided by
     *     CustomerAccountAccessResolver — Contract 03/05's single authority —
     *     never re-derived here from status or lifecycle timestamps.
     *
     * Trial, Active and Grace are usable (Blueprint §27 keeps full access
     * through Grace); Locked, Inactive and Suspended are not.
     *
     * Asserted when a relationship is established (every Agency product
     * action that creates a managed client), and re-asked by every
     * Agency-only capability that consumes an Active relationship — Contract
     * 04's View As on start and on every read — because relationship
     * existence is never eligibility proof (Contract 01 §6).
     */
    public function agencyWorkspaceHasManagementEligibility(Workspace $agencyWorkspace): bool
    {
        if ($this->entitlementManager->getWorkspaceEntitlementSummary($agencyWorkspace)->tier !== WorkspacePlanTier::Agency) {
            return false;
        }

        return ! $this->accountAccessResolver->resolve($agencyWorkspace)->isLocked();
    }

    /**
     * The single implementation of establishing a relationship. create() and
     * createForMigration() differ ONLY in $assertAuthority; everything else a
     * relationship's integrity depends on is here, once.
     *
     * $assertAuthority runs at exactly the point the Agency authority check
     * always has: after both Workspace rows are locked, and before the
     * eligibility gate — so an actor who may not act never learns anything
     * about the Agency's plan or account state, whichever entry point they
     * used.
     *
     * Retried up to 3 attempts on a genuine MySQL deadlock, the same remedy
     * WorkspaceManager::transferOwnership() already uses: this method takes
     * Workspace locks before relationship locks, while terminate() takes
     * them in the opposite order (Contract 01 §7 fixes both orders), so
     * either side may legitimately be chosen as a deadlock victim. Retrying
     * from the start is correct because every read this closure makes is
     * taken again under fresh locks. No lock reordering on either side.
     *
     * @param  Closure(Workspace): void  $assertAuthority  throws UnauthorizedAgencyRelationshipManagementException when the actor may not act
     */
    private function establish(
        int $actorUserId,
        Workspace $agencyWorkspace,
        Workspace $clientWorkspace,
        Closure $assertAuthority,
    ): AgencyClientWorkspaceRelationship {
        $agencyWorkspaceId = (int) $agencyWorkspace->id;
        $clientWorkspaceId = (int) $clientWorkspace->id;

        // Pure argument validation, before any database work: a Workspace
        // can never manage itself (Contract 01 §5).
        if ($agencyWorkspaceId === $clientWorkspaceId) {
            throw new AgencyClientSelfLinkException($agencyWorkspaceId);
        }

        return DB::transaction(function () use ($actorUserId, $agencyWorkspaceId, $clientWorkspaceId, $assertAuthority) {
            // Both Workspace rows are locked in ascending id order, the same
            // discipline WorkspaceManager::reassignBusiness() applies to its
            // own two-Workspace lock, so two operations touching the same
            // pair can never take them in opposite orders. The Client
            // Workspace lock is what serializes two racers — through either
            // entry point — trying to manage the same client.
            $locked = [];

            foreach (collect([$agencyWorkspaceId, $clientWorkspaceId])->sort()->values() as $workspaceId) {
                $workspace = $this->workspaceRepository->findForUpdate((int) $workspaceId);

                if ($workspace === null) {
                    throw new WorkspaceNotFoundException((int) $workspaceId);
                }

                $locked[(int) $workspaceId] = $workspace;
            }

            $lockedAgencyWorkspace = $locked[$agencyWorkspaceId];

            $assertAuthority($lockedAgencyWorkspace);
            $this->assertAgencyWorkspaceHasManagementEligibility($lockedAgencyWorkspace);

            // A locking read, not a snapshot one: an Active row committed by
            // another connection while this transaction waited for its
            // Workspace locks must be visible here, or the duplicate would
            // only be caught by the unique index. It matters most when this
            // runs inside a caller's larger transaction — Contract 10's
            // per-Agency migration transaction — whose snapshot may predate
            // every lock taken here.
            $existing = $this->relationshipRepository->findActiveForClientWorkspaceForUpdate($clientWorkspaceId);

            if ($existing !== null) {
                throw new ClientWorkspaceAlreadyManagedException(
                    $clientWorkspaceId,
                    (int) $existing->agency_workspace_id,
                );
            }

            $relationship = $this->relationshipRepository->create([
                'agency_workspace_id' => $agencyWorkspaceId,
                'client_workspace_id' => $clientWorkspaceId,
                'status' => AgencyClientRelationshipStatus::Active,
                'established_by_user_id' => $actorUserId,
                'established_at' => now(),
            ]);

            AgencyClientRelationshipEstablished::dispatch(
                (int) $relationship->id,
                $agencyWorkspaceId,
                $clientWorkspaceId,
                $actorUserId,
            );

            return $relationship;
        }, 3);
    }

    /**
     * Ordinary Agency-client management authority (Blueprint §2, corrected:
     * Agency team members, not only the owner). Used by create() only, and
     * defined entirely by actorHasAgencyAuthority().
     *
     * Deliberately NOT WorkspaceManager::assertActorIsOwnerOrActiveAdmin():
     * that helper excludes Staff entirely, while V1 grants ordinary Agency
     * management to active Admin AND Staff members of the Agency Workspace.
     * Nothing here reads is_admin or admin Role permissions: a platform
     * account qualifies only if it is independently the Agency Workspace's
     * owner or an active member, and is refused otherwise, whatever Role
     * permissions it holds. There is deliberately no "if is_admin, deny"
     * branch either — platform status neither grants nor erases Agency-side
     * authority.
     */
    private function assertActorMayManageAgencyRelationships(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ($this->actorHasAgencyAuthority($actorUserId, $agencyWorkspace)) {
            return;
        }

        throw new UnauthorizedAgencyRelationshipManagementException($actorUserId, (int) $agencyWorkspace->id);
    }

    /**
     * Migration-operator authority, used by createForMigration() only: a
     * platform relationship operator, and nobody else.
     *
     * There is no owner or Agency-team branch, on purpose. An Agency acting
     * for itself uses create(); this entry point exists solely so the human
     * running Contract 10's migration can be recorded honestly, and admitting
     * Agency actors here would turn it into a second, unaudited create().
     */
    private function assertActorMayOperateRelationshipMigration(int $operatorUserId, Workspace $agencyWorkspace): void
    {
        if ($this->isPlatformRelationshipOperator($operatorUserId)) {
            return;
        }

        throw new UnauthorizedAgencyRelationshipManagementException($operatorUserId, (int) $agencyWorkspace->id);
    }

    /**
     * Termination authority, which is strictly narrower than management
     * authority (Addendum §2): the Agency Workspace owner, or the platform
     * itself. No Agency Admin or Staff member qualifies, whatever
     * permissions they hold, and the Client Workspace can never end its own
     * managing relationship.
     */
    private function assertActorMayTerminateAgencyRelationship(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id === $actorUserId) {
            return;
        }

        if ($this->isPlatformRelationshipOperator($actorUserId)) {
            return;
        }

        throw new UnauthorizedAgencyRelationshipManagementException($actorUserId, (int) $agencyWorkspace->id);
    }

    /**
     * The one definition of "the platform, acting on a relationship" —
     * shared by termination and migration-only establishment so the two
     * platform-reserved acts can never drift apart.
     *
     * An admin-panel account (is_admin) that is either the repository's
     * user-id-1 super admin, or ALSO holds the dedicated permission in its
     * ADMIN Role permissions — never a bare is_admin check, which would
     * silently admit every admin-panel account including narrowly-scoped
     * support roles. This repository has no graduated Platform Owner vs.
     * Platform Administrator distinction to read (mechanically confirmed:
     * User.is_admin is a single flat boolean, and admins.admin_role is
     * vestigial and unread), so this is the minimum safe stand-in for
     * "Platform Owner".
     *
     * DELIBERATELY BYPASSES THE GENERIC GATE. Every other admin capability
     * asks Gate::allows(), which resolves through
     * EloquentAccountRepository::hasPermission() — and that resolves a
     * session permission list first, then, for an account also flagged
     * is_customer, the CUSTOMER permission list, and only otherwise the admin
     * Roles. For these two highly privileged acts that precedence is wrong:
     * a session list or a customer's own stored permissions could satisfy
     * the check by name, while a dual admin+customer account's genuine Role
     * grant could be ignored. So this reads the admin Role permissions
     * directly, through User::getPermissions() (roles -> permissions), and
     * never consults the session, customers.permissions, or any customer-side
     * permission. The generic Gate itself is left unchanged for everything
     * else.
     *
     * User id 1 keeps the repository's existing "first user is always super
     * admin" convention (hasPermission()'s own short-circuit), but only while
     * that account is still is_admin.
     */
    private function isPlatformRelationshipOperator(int $userId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->is_admin !== true) {
            return false;
        }

        return (int) $user->id === 1
            || $user->getPermissions()->contains(self::PLATFORM_RELATIONSHIP_PERMISSION);
    }

    /**
     * The gate on ESTABLISHING the link — a one-time check, never a standing
     * guarantee (Contract 01 §6). Establishing a managed-client relationship
     * is an Agency product action, so it needs the same management
     * eligibility every other one does: Agency tier AND a usable account (a
     * Grace Agency may; a Locked, Inactive or Suspended one may not).
     *
     * Public (Implementation Contract 07 §6 correction): this is the one
     * canonical throwing eligibility gate every Agency product action that
     * creates a managed client must use, establish() included. Making it
     * public lets ClientInvitationManager::send() reuse it verbatim instead
     * of duplicating the entitlement-tier/account-access lookups it wraps —
     * no behavior here changes, only who may call it.
     */
    public function assertAgencyWorkspaceHasManagementEligibility(Workspace $agencyWorkspace): void
    {
        if ($this->agencyWorkspaceHasManagementEligibility($agencyWorkspace)) {
            return;
        }

        $tier = $this->entitlementManager->getWorkspaceEntitlementSummary($agencyWorkspace)->tier;

        throw new AgencyWorkspaceNotEligibleException(
            (int) $agencyWorkspace->id,
            $tier?->value,
            $tier === WorkspacePlanTier::Agency
                ? $this->accountAccessResolver->resolve($agencyWorkspace)->state->value
                : null,
        );
    }
}
