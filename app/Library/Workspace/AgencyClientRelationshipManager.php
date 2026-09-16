<?php

namespace App\Library\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Events\Workspace\AgencyClientRelationshipEstablished;
use App\Events\Workspace\AgencyClientRelationshipTerminated;
use App\Exceptions\Workspace\AgencyClientSelfLinkException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\ClientWorkspaceAlreadyManagedException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The one domain entry point for the canonical Agency -> Client Workspace
 * management relationship (V1 Architecture Decision Addendum §2,
 * Implementation Contract 01).
 *
 * WHAT THIS IS NOT. Nothing here grants access to anything yet. This slice
 * only records the link and its lifecycle; View As, the Agency Clients
 * surface, Location ACL and AgencyRebill are later contracts, and each one
 * authorizes itself at its own call site. In particular an Active row is
 * never proof that the Agency Workspace still holds Agency entitlement
 * (Contract 01 §6) — the tier is asserted once, when the relationship is
 * established, and a later downgrade leaves the row untouched.
 *
 * Agency authority is also never inferred from Client Workspace membership
 * (Addendum §2): every authority decision below reads the AGENCY Workspace's
 * owner and membership rows, and nothing else.
 */
class AgencyClientRelationshipManager
{
    /**
     * The customer-side Gate an Agency team member must additionally hold to
     * perform ordinary Agency-client management. Registered in
     * config/customer-permissions.php and defined as a Gate by
     * AuthServiceProvider's existing generic loop over that file.
     */
    public const MANAGE_PERMISSION = 'manage_agency_clients';

    /**
     * The admin-side Role permission an admin-panel actor must hold to
     * terminate a relationship on the platform's behalf. Registered in
     * config/permissions.php — the ADMIN registry, distinct from the
     * customer one above — and resolved through the same Role/Permission/
     * RoleUser RBAC every other admin capability already uses.
     */
    public const ADMIN_TERMINATE_PERMISSION = 'manage agency relationships';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    /**
     * Establishes $agencyWorkspace as the managing Agency of
     * $clientWorkspace.
     *
     * Takes an already-known Workspace pair rather than assuming an
     * interactive caller (Contract 01 §9), so Contract 10's migration can
     * reuse this method instead of duplicating relationship creation.
     *
     * Deliberately NOT idempotent: this is a single human-initiated action,
     * not a retried side effect, so a second attempt on an already-managed
     * Client Workspace throws rather than silently succeeding.
     *
     * Retried up to 3 attempts on a genuine MySQL deadlock, the same remedy
     * WorkspaceManager::transferOwnership() already uses: this method takes
     * Workspace locks before relationship locks, while terminate() takes
     * them in the opposite order (Contract 01 §7 fixes both orders), so
     * either side may legitimately be chosen as a deadlock victim. Retrying
     * from the start is correct because every read this closure makes is
     * taken again under fresh locks. No lock reordering on either side.
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
        $agencyWorkspaceId = (int) $agencyWorkspace->id;
        $clientWorkspaceId = (int) $clientWorkspace->id;

        // Pure argument validation, before any database work: a Workspace
        // can never manage itself (Contract 01 §5).
        if ($agencyWorkspaceId === $clientWorkspaceId) {
            throw new AgencyClientSelfLinkException($agencyWorkspaceId);
        }

        return DB::transaction(function () use ($actorUserId, $agencyWorkspaceId, $clientWorkspaceId) {
            // Both Workspace rows are locked in ascending id order, the same
            // discipline WorkspaceManager::reassignBusiness() applies to its
            // own two-Workspace lock, so two operations touching the same
            // pair can never take them in opposite orders. The Client
            // Workspace lock is what serializes two different Agencies
            // racing to manage the same client.
            $locked = [];

            foreach (collect([$agencyWorkspaceId, $clientWorkspaceId])->sort()->values() as $workspaceId) {
                $workspace = $this->workspaceRepository->findForUpdate((int) $workspaceId);

                if ($workspace === null) {
                    throw new WorkspaceNotFoundException((int) $workspaceId);
                }

                $locked[(int) $workspaceId] = $workspace;
            }

            $lockedAgencyWorkspace = $locked[$agencyWorkspaceId];

            $this->assertActorMayManageAgencyRelationships($actorUserId, $lockedAgencyWorkspace);
            $this->assertAgencyWorkspaceIsOnTheAgencyTier($lockedAgencyWorkspace);

            // A locking read, not a snapshot one: an Active row committed by
            // another connection while this transaction waited for its
            // Workspace locks must be visible here, or the duplicate would
            // only be caught by the unique index.
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
     * Ends an Agency's management of a Client Workspace.
     *
     * The row is never deleted (Addendum §2): it keeps its own establishment
     * audit and gains the termination one. Terminating an already-terminated
     * relationship is an authorized no-op that returns the row untouched —
     * authority is still asserted first, and the original termination audit
     * is never overwritten — following WorkspaceManager::deactivateWorkspace()'s
     * own duplicate-transition precedent.
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
     * Ordinary Agency-client management authority (Blueprint §2, corrected:
     * Agency team members, not only the owner).
     *
     * Deliberately NOT WorkspaceManager::assertActorIsOwnerOrActiveAdmin():
     * that helper excludes Staff entirely and cannot express "any active
     * member holding the permission", which is exactly the rule here. The
     * permission is necessary but never sufficient — it only counts for an
     * ACTIVE member of the AGENCY Workspace.
     */
    private function assertActorMayManageAgencyRelationships(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($agencyWorkspace, $actorUserId);

        if ($membership !== null && $membership->is_active) {
            $user = User::query()->find($actorUserId);

            if ($user !== null && Gate::forUser($user)->allows(self::MANAGE_PERMISSION)) {
                return;
            }
        }

        throw new UnauthorizedAgencyRelationshipManagementException($actorUserId, (int) $agencyWorkspace->id);
    }

    /**
     * Termination authority, which is strictly narrower than management
     * authority (Addendum §2): the Agency Workspace owner, or the platform
     * itself. No Agency Admin or Staff member qualifies, whatever
     * permissions they hold, and the Client Workspace can never end its own
     * managing relationship.
     *
     * The platform side is an admin-panel actor who ALSO holds the dedicated
     * admin-side Role permission — never a bare is_admin check, which would
     * silently admit every admin-panel account including narrowly-scoped
     * support roles. This repository has no graduated Platform Owner vs.
     * Platform Administrator distinction to read (mechanically confirmed:
     * User.is_admin is a single flat boolean, and admins.admin_role is
     * vestigial and unread), so requiring both the flag and a dedicated Role
     * permission is the minimum safe stand-in for "Platform Owner".
     */
    private function assertActorMayTerminateAgencyRelationship(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id === $actorUserId) {
            return;
        }

        $user = User::query()->find($actorUserId);

        if ($user !== null
            && (bool) $user->is_admin
            && Gate::forUser($user)->allows(self::ADMIN_TERMINATE_PERMISSION)
        ) {
            return;
        }

        throw new UnauthorizedAgencyRelationshipManagementException($actorUserId, (int) $agencyWorkspace->id);
    }

    /**
     * A one-time gate on ESTABLISHING the link, never a standing guarantee
     * (Contract 01 §6).
     *
     * Reuses EntitlementManager's own public summary rather than repeating
     * its workspace_plan_assignments -> workspace_plan_catalog read, the
     * same way WorkspaceController already asks whether a Workspace is on
     * the Agency tier.
     */
    private function assertAgencyWorkspaceIsOnTheAgencyTier(Workspace $agencyWorkspace): void
    {
        $tier = $this->entitlementManager->getWorkspaceEntitlementSummary($agencyWorkspace)->tier;

        if ($tier !== WorkspacePlanTier::Agency) {
            throw new AgencyWorkspaceNotEligibleException((int) $agencyWorkspace->id, $tier?->value);
        }
    }
}
