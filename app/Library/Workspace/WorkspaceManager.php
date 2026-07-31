<?php

namespace App\Library\Workspace;

use App\DTO\Workspace\WorkspaceFirstBusinessInput;
use App\DTO\Workspace\WorkspaceOwnershipTransferDisposition;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Workspace\WorkspaceOwnershipTransferMode;
use App\Enums\Workspace\WorkspaceTransitionType;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Events\Workspace\BusinessReassignedToWorkspace;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeactivated;
use App\Events\Workspace\WorkspaceMembershipBusinessAccessScopeChanged;
use App\Events\Workspace\WorkspaceMembershipBusinessAssigned;
use App\Events\Workspace\WorkspaceMembershipBusinessUnassigned;
use App\Events\Workspace\WorkspaceMembershipCreated;
use App\Events\Workspace\WorkspaceMembershipDeactivated;
use App\Events\Workspace\WorkspaceMembershipReactivated;
use App\Events\Workspace\WorkspaceMembershipRoleChanged;
use App\Events\Workspace\WorkspaceOwnershipTransferred;
use App\Events\Workspace\WorkspaceReactivated;
use App\Events\Workspace\WorkspaceRenamed;
use App\Exceptions\Workspace\BusinessAssignmentRequiresSelectedScopeException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use App\Exceptions\Workspace\WorkspaceInvalidIncomingOwnerException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Exceptions\Workspace\WorkspaceMembershipNotFoundException;
use App\Exceptions\Workspace\WorkspaceMembershipWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Exceptions\Workspace\WorkspaceOwnerNotFoundException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Repositories\Contracts\WorkspaceTransitionRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Narrow compatibility resolver (RFC-003 §13.1) for the one still-active
 * legacy Business-creation write path (§6 finding 8). Exists only to
 * supply an explicit Workspace to that path — it is never wired into any
 * generic repository method, never a general-purpose owner-to-Workspace
 * inference API, and never chooses a candidate arbitrarily when more than
 * one exists.
 */
class WorkspaceManager
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        private readonly WorkspaceTransitionRepository $transitionRepository,
    ) {
    }

    /**
     * RFC-003 §14.1's effective Business-access algorithm. Platform-
     * administrator access is deliberately not evaluated here — §14 states
     * it is "checked upstream of, and independently from, everything
     * below" by existing backend authorization, and RFC-003 "does not add
     * to, wrap, or duplicate this path."
     *
     * Re-reads Business and Workspace via their repositories rather than
     * trusting the caller's in-memory $business (and its relations) for
     * the load-bearing fields (workspace_id, customer_id, is_active,
     * owner_user_id) — a caller-held model can be stale relative to the
     * current persisted row. Performs no write, event dispatch, or
     * transition creation.
     */
    public function userCanAccessBusiness(int $userId, Business $business): bool
    {
        $currentBusiness = $this->businessRepository->findById($business->id);

        if ($currentBusiness === null || $currentBusiness->workspace_id === null) {
            // No Workspace to evaluate against — §14.1: "workspace is
            // null" only occurs pre-M1B; handled defensively, not assumed
            // impossible for every historical row.
            return false;
        }

        $workspace = $this->workspaceRepository->findById($currentBusiness->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            // An inactive (or missing) Workspace blocks ALL customer-side
            // access to this Business, including direct ownership —
            // evaluated before any ownership/membership check.
            return false;
        }

        if ((int) $currentBusiness->customer_id === $userId) {
            return true;
        }

        if ((int) $workspace->owner_user_id === $userId) {
            // The Workspace owner always has full access, never
            // scope-limited (§7.3) — independent of Business.customer_id.
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return false;
        }

        if ($membership->business_access_scope === WorkspaceBusinessAccessScope::All) {
            return true;
        }

        return $this->membershipBusinessRepository->isAssigned($membership, $currentBusiness->id);
    }

    /**
     * Delegates entirely to userCanAccessBusiness() — no second,
     * independent access algorithm. Performs no write.
     */
    public function assertUserCanAccessBusiness(int $userId, Business $business): void
    {
        if (! $this->userCanAccessBusiness($userId, $business)) {
            throw new WorkspaceAccessDeniedException($userId, $business->id);
        }
    }

    /**
     * Creates a new Workspace, optionally with its first Business in the
     * same transaction (RFC-003 §16.1, Milestone 2 domain contract §1).
     * The owner User row is locked first via the same proven pattern as
     * resolveLegacyOnboardingWorkspace() (§13.1 step 2, §18) — there is no
     * Workspace row to lock yet. $firstBusiness's Customer is always used
     * as supplied; Business.customer_id is never inferred from
     * $ownerUserId. No workspace_transitions row is written — durable
     * audit is limited to ownership transfer and cross-Workspace Business
     * reassignment.
     */
    public function createWorkspace(
        int $ownerUserId,
        string $name,
        ?WorkspaceFirstBusinessInput $firstBusiness = null,
    ): Workspace {
        return DB::transaction(function () use ($ownerUserId, $name, $firstBusiness) {
            try {
                $userRow = $this->lockOwnerRow($ownerUserId);

                if ($userRow === null) {
                    throw (new ModelNotFoundException())->setModel(User::class, [$ownerUserId]);
                }
            } catch (ModelNotFoundException) {
                // Typed Workspace-domain boundary exception (Milestone 2
                // domain contract) — scoped to createWorkspace() only;
                // resolveLegacyOnboardingWorkspace()'s own
                // ModelNotFoundException behavior is untouched.
                throw new WorkspaceOwnerNotFoundException($ownerUserId);
            }

            $workspace = $this->workspaceRepository->create([
                'owner_user_id' => $ownerUserId,
                'name' => trim($name),
                'is_active' => true,
            ]);

            $business = null;

            if ($firstBusiness !== null) {
                $business = $this->businessRepository->createForCustomerInWorkspace(
                    $firstBusiness->customer,
                    $workspace,
                    $firstBusiness->businessAttributes,
                );
            }

            WorkspaceCreated::dispatch($workspace->id, $ownerUserId);

            if ($business !== null) {
                BusinessAssignedToWorkspace::dispatch($business->id, $workspace->id, $ownerUserId);
            }

            return $workspace;
        });
    }

    /**
     * Renames a Workspace (Milestone 2 domain contract). Requires
     * owner-or-active-admin authority and an active Workspace. Re-locks
     * and reloads the Workspace rather than trusting the caller's
     * possibly-stale $workspace for owner_user_id, is_active or the
     * current name. An unchanged (post-trim) name is an authorized no-op:
     * no write, no event.
     */
    public function renameWorkspace(int $actorUserId, Workspace $workspace, string $name): Workspace
    {
        return DB::transaction(function () use ($actorUserId, $workspace, $name) {
            $locked = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($locked === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            if (! $locked->is_active) {
                throw new InactiveWorkspaceMutationException($locked->id);
            }

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $locked);

            $normalizedName = trim($name);

            if ($normalizedName === $locked->name) {
                return $locked;
            }

            $previousName = $locked->name;
            $updated = $this->workspaceRepository->update($locked, ['name' => $normalizedName]);

            WorkspaceRenamed::dispatch($locked->id, $actorUserId, $previousName, $normalizedName);

            return $updated;
        });
    }

    /**
     * Deactivates a Workspace (Milestone 2 domain contract). Requires
     * owner authority — a duplicate deactivation on an already-inactive
     * Workspace is an authorized no-op, but authority is still checked
     * before that no-op is returned.
     */
    public function deactivateWorkspace(int $actorUserId, Workspace $workspace): Workspace
    {
        return DB::transaction(function () use ($actorUserId, $workspace) {
            $locked = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($locked === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertActorIsOwner($actorUserId, $locked);

            if (! $locked->is_active) {
                return $locked;
            }

            $updated = $this->workspaceRepository->setActive($locked, false);

            WorkspaceDeactivated::dispatch($locked->id, $actorUserId);

            return $updated;
        });
    }

    /**
     * Reactivates a Workspace (Milestone 2 domain contract) — the only
     * normal mutation permitted to change an inactive Workspace back to
     * active. Requires owner authority; a duplicate reactivation on an
     * already-active Workspace is an authorized no-op, checked after
     * authority.
     */
    public function reactivateWorkspace(int $actorUserId, Workspace $workspace): Workspace
    {
        return DB::transaction(function () use ($actorUserId, $workspace) {
            $locked = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($locked === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            $this->assertActorIsOwner($actorUserId, $locked);

            if ($locked->is_active) {
                return $locked;
            }

            $updated = $this->workspaceRepository->setActive($locked, true);

            WorkspaceReactivated::dispatch($locked->id, $actorUserId);

            return $updated;
        });
    }

    /**
     * Owner-only authority assertion (Milestone 2 domain contract §8). No
     * platform-administrator bypass; users.parent_id is never consulted.
     */
    private function assertActorIsOwner(int $actorUserId, Workspace $workspace): void
    {
        if ((int) $workspace->owner_user_id !== $actorUserId) {
            throw new UnauthorizedWorkspaceManagementException($actorUserId, $workspace->id);
        }
    }

    /**
     * Owner-or-active-admin authority assertion (Milestone 2 domain
     * contract §8). An active Workspace-membership row with role Admin
     * grants authority; an inactive admin membership or any staff
     * membership does not. No platform-administrator bypass; users.parent_id
     * is never consulted.
     */
    private function assertActorIsOwnerOrActiveAdmin(int $actorUserId, Workspace $workspace): void
    {
        if ((int) $workspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);

        if ($membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
        ) {
            return;
        }

        throw new UnauthorizedWorkspaceManagementException($actorUserId, $workspace->id);
    }

    /**
     * Adds a new active WorkspaceMembership (Milestone 2 domain contract).
     * The Workspace owner never receives a membership row (§7.3) —
     * memberUserId equal to owner_user_id is always rejected. An existing
     * active membership that exactly matches the requested role, scope,
     * and normalized selected Business-ID set is an authorized no-op; any
     * other existing row (active-but-different, or inactive) throws
     * rather than silently overwriting it.
     */
    public function addMember(
        int $actorUserId,
        Workspace $workspace,
        int $memberUserId,
        WorkspaceMembershipRole $role,
        WorkspaceBusinessAccessScope $scope,
        array $businessIds = [],
    ): WorkspaceMembership {
        return DB::transaction(function () use ($actorUserId, $workspace, $memberUserId, $role, $scope, $businessIds) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            $this->assertHasAuthorityOverRole($actorUserId, $lockedWorkspace, $role);

            if ($memberUserId === (int) $lockedWorkspace->owner_user_id) {
                throw new OwnerCannotBeMemberException($lockedWorkspace->id, $memberUserId);
            }

            if ($scope === WorkspaceBusinessAccessScope::All && $businessIds !== []) {
                throw new InvalidBusinessAccessScopeAssignmentException($scope, count($businessIds));
            }

            $normalizedIds = $this->normalizeBusinessIds($businessIds);

            $existing = $this->membershipRepository->findByWorkspaceAndUserForUpdate($lockedWorkspace->id, $memberUserId);

            if ($existing !== null) {
                if (! $existing->is_active) {
                    throw new WorkspaceMembershipAlreadyExistsException($lockedWorkspace->id, $memberUserId, false);
                }

                $idsMatch = true;

                if ($existing->business_access_scope === $scope && $scope === WorkspaceBusinessAccessScope::Selected) {
                    $currentIds = $this->normalizeBusinessIds(
                        $this->membershipBusinessRepository->assignedBusinessIds($existing)->all()
                    );
                    $idsMatch = $currentIds === $normalizedIds;
                }

                if ($existing->role === $role && $existing->business_access_scope === $scope && $idsMatch) {
                    return $existing;
                }

                throw new WorkspaceMembershipAlreadyExistsException($lockedWorkspace->id, $memberUserId, true);
            }

            $membership = $this->membershipRepository->create($lockedWorkspace, $memberUserId, $role, $scope);

            if ($scope === WorkspaceBusinessAccessScope::Selected && $normalizedIds !== []) {
                $this->membershipBusinessRepository->syncForMembership($membership, $normalizedIds);
            }

            WorkspaceMembershipCreated::dispatch(
                $membership->id,
                $lockedWorkspace->id,
                $memberUserId,
                $actorUserId,
                $role->value,
                $scope->value,
            );

            if ($scope === WorkspaceBusinessAccessScope::Selected) {
                foreach ($normalizedIds as $businessId) {
                    WorkspaceMembershipBusinessAssigned::dispatch($membership->id, $lockedWorkspace->id, $businessId, $actorUserId);
                }
            }

            return $membership;
        });
    }

    /**
     * Changes a WorkspaceMembership's role (Milestone 2 domain contract).
     * Owner-only — an active Admin cannot promote, demote, or otherwise
     * change any membership's role, its own included. The Workspace owner
     * itself never holds a membership row (§7.3); this method only ever
     * operates on genuine member rows.
     *
     * Slice 2D correction: the Workspace lock uses the caller-supplied
     * Membership's workspace_id, and the Membership is separately locked by
     * ID — these are two independent lookups, so once both rows are locked
     * their relationship is re-verified rather than assumed. A caller that
     * mutated $membership->workspace_id in memory cannot borrow authority
     * from a Workspace that doesn't actually own the locked Membership.
     */
    public function changeMemberRole(int $actorUserId, WorkspaceMembership $membership, WorkspaceMembershipRole $role): WorkspaceMembership
    {
        return DB::transaction(function () use ($actorUserId, $membership, $role) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            if (! $lockedMembership->is_active) {
                throw new InactiveWorkspaceMembershipMutationException($lockedMembership->id);
            }

            $this->assertActorIsOwner($actorUserId, $lockedWorkspace);

            if ($lockedMembership->role === $role) {
                return $lockedMembership;
            }

            $previousRole = $lockedMembership->role;
            $updated = $this->membershipRepository->updateRole($lockedMembership, $role);

            WorkspaceMembershipRoleChanged::dispatch(
                $lockedMembership->id,
                $lockedWorkspace->id,
                $lockedMembership->user_id,
                $actorUserId,
                $previousRole->value,
                $role->value,
            );

            return $updated;
        });
    }

    /**
     * Deactivates a WorkspaceMembership (Milestone 2 domain contract).
     * Authority depends on the target membership's current role — Admin
     * targets require the Workspace owner; Staff targets accept the owner
     * or any active Admin, applied identically regardless of whether the
     * actor is deactivating themselves. Every workspace_membership_businesses
     * row is retained (never cleared) — deactivation only flips is_active.
     *
     * Slice 2D correction: the Workspace lock uses the caller-supplied
     * Membership's workspace_id, and the Membership is separately locked by
     * ID — these are two independent lookups, so once both rows are locked
     * their relationship is re-verified rather than assumed. A caller that
     * mutated $membership->workspace_id in memory cannot borrow authority
     * from a Workspace that doesn't actually own the locked Membership.
     */
    public function deactivateMember(int $actorUserId, WorkspaceMembership $membership): WorkspaceMembership
    {
        return DB::transaction(function () use ($actorUserId, $membership) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            $this->assertHasAuthorityOverRole($actorUserId, $lockedWorkspace, $lockedMembership->role);

            if (! $lockedMembership->is_active) {
                return $lockedMembership;
            }

            $updated = $this->membershipRepository->setActive($lockedMembership, false);

            WorkspaceMembershipDeactivated::dispatch($lockedMembership->id, $lockedWorkspace->id, $lockedMembership->user_id, $actorUserId);

            return $updated;
        });
    }

    /**
     * Reactivates a WorkspaceMembership (Milestone 2 domain contract).
     * Same target-role authority rule as deactivateMember(). Every
     * workspace_membership_businesses row was retained through
     * deactivation, so flipping is_active back to true alone restores
     * effective access — §14.1 re-reads membership state fresh on every
     * evaluation, so no separate assignment-row restoration step exists.
     *
     * Slice 2D correction: the Workspace lock uses the caller-supplied
     * Membership's workspace_id, and the Membership is separately locked by
     * ID — these are two independent lookups, so once both rows are locked
     * their relationship is re-verified rather than assumed. A caller that
     * mutated $membership->workspace_id in memory cannot borrow authority
     * from a Workspace that doesn't actually own the locked Membership.
     */
    public function reactivateMember(int $actorUserId, WorkspaceMembership $membership): WorkspaceMembership
    {
        return DB::transaction(function () use ($actorUserId, $membership) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            $this->assertHasAuthorityOverRole($actorUserId, $lockedWorkspace, $lockedMembership->role);

            if ($lockedMembership->is_active) {
                return $lockedMembership;
            }

            $updated = $this->membershipRepository->setActive($lockedMembership, true);

            WorkspaceMembershipReactivated::dispatch($lockedMembership->id, $lockedWorkspace->id, $lockedMembership->user_id, $actorUserId);

            return $updated;
        });
    }

    /**
     * Changes a WorkspaceMembership's Business-access scope (RFC-003 §7.5,
     * Milestone 2 Slice 2E), synchronizing workspace_membership_businesses
     * as the enum transitions between `all` and `selected`. Owner-or-
     * active-Admin authority (same rule as renameWorkspace()) — Staff,
     * inactive Admin, and unrelated Users are all denied. Same Workspace/
     * Membership consistency correction as Slice 2D: the Workspace lock
     * uses the caller-supplied Membership's workspace_id, the Membership
     * is locked separately by ID, and their relationship is re-verified
     * rather than assumed.
     *
     * `all` -> `all` and an identical `selected` -> `selected` normalized
     * set are both authorized no-ops (no write, no event).
     * `all` -> `selected` and a changed `selected` -> `selected` set both
     * synchronize the complete requested set via
     * WorkspaceMembershipBusinessRepository::syncForMembership() — which
     * validates every ID (existence, then same-Workspace membership)
     * before any assignment row changes, so a mixed valid/invalid ID set
     * writes nothing and throws CrossWorkspaceAssignmentException, rolling
     * back this method's own transaction, including any scope write.
     * `selected` -> `all` clears every assignment row.
     */
    public function changeMemberBusinessAccessScope(
        int $actorUserId,
        WorkspaceMembership $membership,
        WorkspaceBusinessAccessScope $scope,
        array $businessIds = [],
    ): WorkspaceMembership {
        return DB::transaction(function () use ($actorUserId, $membership, $scope, $businessIds) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            if (! $lockedMembership->is_active) {
                throw new InactiveWorkspaceMembershipMutationException($lockedMembership->id);
            }

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            if ($scope === WorkspaceBusinessAccessScope::All && $businessIds !== []) {
                throw new InvalidBusinessAccessScopeAssignmentException($scope, count($businessIds));
            }

            $currentScope = $lockedMembership->business_access_scope;
            $currentIds = $this->normalizeBusinessIds(
                $this->membershipBusinessRepository->assignedBusinessIds($lockedMembership)->all()
            );
            $requestedIds = $this->normalizeBusinessIds($businessIds);

            // A. all -> all: authorized no-op.
            if ($currentScope === WorkspaceBusinessAccessScope::All && $scope === WorkspaceBusinessAccessScope::All) {
                return $lockedMembership;
            }

            // D (no-op case). selected -> selected, identical normalized set.
            if ($currentScope === WorkspaceBusinessAccessScope::Selected
                && $scope === WorkspaceBusinessAccessScope::Selected
                && $currentIds === $requestedIds
            ) {
                return $lockedMembership;
            }

            // C. selected -> all: clear every assignment row.
            if ($currentScope === WorkspaceBusinessAccessScope::Selected && $scope === WorkspaceBusinessAccessScope::All) {
                $this->membershipBusinessRepository->syncForMembership($lockedMembership, []);
                $updated = $this->membershipRepository->updateBusinessAccessScope($lockedMembership, $scope);

                foreach ($currentIds as $businessId) {
                    WorkspaceMembershipBusinessUnassigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $businessId, $actorUserId);
                }

                WorkspaceMembershipBusinessAccessScopeChanged::dispatch(
                    $lockedMembership->id,
                    $lockedWorkspace->id,
                    $lockedMembership->user_id,
                    $actorUserId,
                    $currentScope->value,
                    $scope->value,
                );

                return $updated;
            }

            // B. all -> selected, or D (changed case). selected -> selected
            // with a changed set. Both synchronize the complete requested
            // set first.
            $this->membershipBusinessRepository->syncForMembership($lockedMembership, $requestedIds);

            $added = $this->addedIds($currentIds, $requestedIds);
            $removed = $this->removedIds($currentIds, $requestedIds);

            if ($currentScope === WorkspaceBusinessAccessScope::All) {
                $updated = $this->membershipRepository->updateBusinessAccessScope($lockedMembership, $scope);

                WorkspaceMembershipBusinessAccessScopeChanged::dispatch(
                    $lockedMembership->id,
                    $lockedWorkspace->id,
                    $lockedMembership->user_id,
                    $actorUserId,
                    $currentScope->value,
                    $scope->value,
                );

                foreach ($added as $businessId) {
                    WorkspaceMembershipBusinessAssigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $businessId, $actorUserId);
                }

                return $updated;
            }

            // D (changed case): selected -> selected. The enum value did
            // not change, so no scope-changed event is dispatched.
            foreach ($added as $businessId) {
                WorkspaceMembershipBusinessAssigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $businessId, $actorUserId);
            }

            foreach ($removed as $businessId) {
                WorkspaceMembershipBusinessUnassigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $businessId, $actorUserId);
            }

            return $lockedMembership;
        });
    }

    /**
     * Directly assigns one Business to a `selected`-scope Membership
     * (RFC-003 §7.5, Milestone 2 Slice 2E). Same locking, consistency,
     * active-state and authority rules as changeMemberBusinessAccessScope().
     * Re-reads the Business via BusinessRepository::findById() — the
     * authoritative persisted lookup — rather than trusting the caller's
     * $business for workspace_id; WorkspaceMembershipBusinessRepository::
     * assign() re-verifies the same-Workspace invariant against that
     * authoritative row before writing. Idempotent: an already-assigned
     * Business is an authorized no-op returning the existing persisted
     * assignment, detected via Eloquent's wasRecentlyCreated on the
     * firstOrCreate() result inside assign() — no new repository method
     * or Manager-level raw database query is needed.
     */
    public function assignBusinessToMember(
        int $actorUserId,
        WorkspaceMembership $membership,
        Business $business,
    ): WorkspaceMembershipBusiness {
        return DB::transaction(function () use ($actorUserId, $membership, $business) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            if (! $lockedMembership->is_active) {
                throw new InactiveWorkspaceMembershipMutationException($lockedMembership->id);
            }

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            if ($lockedMembership->business_access_scope !== WorkspaceBusinessAccessScope::Selected) {
                throw new BusinessAssignmentRequiresSelectedScopeException($lockedMembership->id);
            }

            $lockedBusiness = $this->businessRepository->findById($business->id);

            if ($lockedBusiness === null) {
                throw new WorkspaceBusinessNotFoundException($business->id);
            }

            $assignment = $this->membershipBusinessRepository->assign($lockedMembership, $lockedBusiness);

            if (! $assignment->wasRecentlyCreated) {
                return $assignment;
            }

            WorkspaceMembershipBusinessAssigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $lockedBusiness->id, $actorUserId);

            return $assignment;
        });
    }

    /**
     * Directly unassigns one Business from a `selected`-scope Membership
     * (RFC-003 §7.5, Milestone 2 Slice 2E). Same locking, consistency,
     * active-state and authority rules as assignBusinessToMember(). Unlike
     * assign(), WorkspaceMembershipBusinessRepository::unassign() performs
     * no same-Workspace check of its own (it is an unconditional delete by
     * composite key), so the cross-Workspace comparison against the
     * authoritative locked Business/Workspace happens here — a plain
     * attribute comparison on already-locked/loaded models, not a new
     * repository method or raw database query.
     */
    public function unassignBusinessFromMember(
        int $actorUserId,
        WorkspaceMembership $membership,
        Business $business,
    ): void {
        DB::transaction(function () use ($actorUserId, $membership, $business) {
            $expectedWorkspaceId = (int) $membership->workspace_id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            $lockedMembership = $this->membershipRepository->findForUpdate($membership->id);

            if ($lockedMembership === null) {
                throw new WorkspaceMembershipNotFoundException($lockedWorkspace->id, $membership->user_id);
            }

            if ((int) $lockedMembership->workspace_id !== (int) $lockedWorkspace->id) {
                throw new WorkspaceMembershipWorkspaceMismatchException(
                    $lockedMembership->id,
                    (int) $lockedWorkspace->id,
                    (int) $lockedMembership->workspace_id,
                );
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            if (! $lockedMembership->is_active) {
                throw new InactiveWorkspaceMembershipMutationException($lockedMembership->id);
            }

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            if ($lockedMembership->business_access_scope !== WorkspaceBusinessAccessScope::Selected) {
                throw new BusinessAssignmentRequiresSelectedScopeException($lockedMembership->id);
            }

            $lockedBusiness = $this->businessRepository->findById($business->id);

            if ($lockedBusiness === null) {
                throw new WorkspaceBusinessNotFoundException($business->id);
            }

            if ((int) $lockedBusiness->workspace_id !== (int) $lockedWorkspace->id) {
                throw new CrossWorkspaceAssignmentException(
                    "WorkspaceMembership [{$lockedMembership->id}] belongs to Workspace [{$lockedWorkspace->id}]; " .
                    'Business [' . $lockedBusiness->id . '] belongs to Workspace [' . ($lockedBusiness->workspace_id ?? 'null') . '].'
                );
            }

            if (! $this->membershipBusinessRepository->isAssigned($lockedMembership, $lockedBusiness->id)) {
                return;
            }

            $this->membershipBusinessRepository->unassign($lockedMembership, $lockedBusiness->id);

            WorkspaceMembershipBusinessUnassigned::dispatch($lockedMembership->id, $lockedWorkspace->id, $lockedBusiness->id, $actorUserId);
        });
    }

    /**
     * Creates a Business inside an explicit target Workspace (RFC-003
     * §16.1, Milestone 2 Slice 2F). Owner-or-active-Admin authority (same
     * rule as renameWorkspace()/changeMemberBusinessAccessScope()). The
     * Workspace is re-locked and reloaded rather than trusting the
     * caller's possibly-stale $workspace. $customer is always the explicit
     * caller-supplied Customer — never inferred from $workspace's
     * owner_user_id or from $actorUserId — so Business.customer_id stays
     * fully independent of Workspace ownership (§11.2), matching
     * createWorkspace()'s existing WorkspaceFirstBusinessInput posture.
     * createForCustomerInWorkspace() sets workspace_id before its single
     * insert, so the created row never has a null workspace_id window.
     * No workspace_transitions row is written — durable audit is limited
     * to cross-Workspace reassignment and (future) ownership transfer.
     */
    public function createBusinessInWorkspace(
        int $actorUserId,
        Customer $customer,
        Workspace $workspace,
        array $businessAttributes,
    ): Business {
        return DB::transaction(function () use ($actorUserId, $customer, $workspace, $businessAttributes) {
            $expectedWorkspaceId = (int) $workspace->id;

            $lockedWorkspace = $this->workspaceRepository->findForUpdate($expectedWorkspaceId);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($expectedWorkspaceId);
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedWorkspace);

            $business = $this->businessRepository->createForCustomerInWorkspace($customer, $lockedWorkspace, $businessAttributes);

            BusinessAssignedToWorkspace::dispatch($business->id, $lockedWorkspace->id, $actorUserId);

            return $business;
        });
    }

    /**
     * Reassigns an existing Business to a different Workspace (RFC-003
     * §16.2, Milestone 2 Slice 2F) — an explicit operation, never an
     * implicit side effect of another one. Locks every distinct Workspace
     * involved (source and target — one row when they're the same) in
     * ascending ID order before locking the Business, so two concurrent
     * reassignments moving Businesses in opposite directions between the
     * same two Workspaces can never acquire those locks in reverse order
     * relative to each other (§18). The Business is then locked by ID and
     * its authoritative workspace_id re-verified against the caller-
     * supplied Business's expected source — a stale/mutated caller model
     * cannot borrow authority from a Workspace the Business no longer (or
     * never did) belong to. Authority and active-state are required over
     * both Workspaces independently; authority over only one is
     * insufficient. A same-target call is an authorized no-op evaluated
     * only after every lock/consistency/authority/active-state check has
     * already passed — it still performs no cleanup, write, transition or
     * event. Only workspace_id changes; customer_id is never touched
     * (BusinessRepository::reassignWorkspace()'s own narrow contract).
     * Scoped assignment grants against the old Workspace's memberships are
     * removed first (§9.3's Workspace-equality rule), then exactly one
     * workspace_transitions row is written before any event dispatch.
     */
    public function reassignBusiness(
        int $actorUserId,
        Business $business,
        Workspace $targetWorkspace,
    ): Business {
        return DB::transaction(function () use ($actorUserId, $business, $targetWorkspace) {
            $expectedSourceWorkspaceId = (int) $business->workspace_id;
            $targetWorkspaceId = (int) $targetWorkspace->id;

            $workspaceIdsToLock = collect([$expectedSourceWorkspaceId, $targetWorkspaceId])
                ->unique()
                ->sort()
                ->values();

            $lockedWorkspaces = [];

            foreach ($workspaceIdsToLock as $workspaceId) {
                $locked = $this->workspaceRepository->findForUpdate($workspaceId);

                if ($locked === null) {
                    throw new WorkspaceNotFoundException($workspaceId);
                }

                $lockedWorkspaces[$workspaceId] = $locked;
            }

            $lockedBusiness = $this->businessRepository->findForUpdate($business->id);

            if ($lockedBusiness === null) {
                throw new WorkspaceBusinessNotFoundException($business->id);
            }

            if ((int) $lockedBusiness->workspace_id !== $expectedSourceWorkspaceId) {
                throw new BusinessWorkspaceMismatchException(
                    $lockedBusiness->id,
                    $expectedSourceWorkspaceId,
                    (int) $lockedBusiness->workspace_id,
                );
            }

            $lockedSourceWorkspace = $lockedWorkspaces[$expectedSourceWorkspaceId];
            $lockedTargetWorkspace = $lockedWorkspaces[$targetWorkspaceId];

            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedSourceWorkspace);
            $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedTargetWorkspace);

            if (! $lockedSourceWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedSourceWorkspace->id);
            }

            if (! $lockedTargetWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedTargetWorkspace->id);
            }

            if ($expectedSourceWorkspaceId === $targetWorkspaceId) {
                return $lockedBusiness;
            }

            $removedGrants = $this->membershipBusinessRepository
                ->removeAllForBusinessInWorkspace($lockedBusiness->id, $lockedSourceWorkspace->id)
                ->sortBy([
                    ['workspace_membership_id', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            $updated = $this->businessRepository->reassignWorkspace($lockedBusiness, $lockedTargetWorkspace);

            $this->transitionRepository->create([
                'workspace_id' => $lockedTargetWorkspace->id,
                'transition_type' => WorkspaceTransitionType::BusinessReassigned,
                'actor_user_id' => $actorUserId,
                'from_owner_user_id' => null,
                'to_owner_user_id' => null,
                'previous_owner_disposition' => null,
                'business_id' => $updated->id,
                'from_workspace_id' => $lockedSourceWorkspace->id,
            ]);

            foreach ($removedGrants as $grant) {
                WorkspaceMembershipBusinessUnassigned::dispatch(
                    $grant->workspace_membership_id,
                    $lockedSourceWorkspace->id,
                    $updated->id,
                    $actorUserId,
                );
            }

            BusinessReassignedToWorkspace::dispatch($updated->id, $lockedSourceWorkspace->id, $lockedTargetWorkspace->id, $actorUserId);

            return $updated;
        });
    }

    /**
     * Transfers Workspace ownership (RFC-003 §15, Milestone 2 Slice 2G) —
     * owner-only authority, no active-Admin bypass. Changes exactly one
     * `workspaces` column (owner_user_id, via WorkspaceRepository::
     * transferOwnership()); every membership write below is a separate,
     * expected side effect of the same transaction, not a change to that
     * single-column scope. A same-owner call is an authorized no-op,
     * evaluated only after authority/active-state checks — the supplied
     * disposition is ignored, and nothing is written.
     *
     * For a real transfer: both the previous and incoming owner's `users`
     * rows are locked in ascending ID order (there is no Workspace-derived
     * lock that serializes concurrent transfers touching the same User),
     * then both membership rows (a missing row is valid, represented by
     * null) via findByWorkspaceAndUserForUpdate() in the same ascending
     * order. The incoming owner's existing active membership (if any) is
     * deactivated — never deleted, since owner status is derived solely
     * from owner_user_id (§7.2) and never coexists with a membership row
     * for the same user — before owner_user_id itself changes, so no
     * active membership for the previous owner can ever be created before
     * the column flips. The previous owner's disposition (deactivate or
     * convert-to-admin) is reconciled after that, then exactly one
     * workspace_transitions row is written before any event dispatch.
     * Cross-Workspace Business IDs in a convert-to-admin disposition
     * surface as CrossWorkspaceAssignmentException from the same
     * syncForMembership() validation reassignBusiness()/
     * changeMemberBusinessAccessScope() already rely on, rolling back this
     * method's entire transaction — deactivation, ownership column,
     * reconciliation, assignments, transition, and every event together.
     */
    public function transferOwnership(
        int $actorUserId,
        Workspace $workspace,
        int $newOwnerUserId,
        WorkspaceOwnershipTransferDisposition $previousOwnerDisposition,
    ): Workspace {
        return DB::transaction(function () use ($actorUserId, $workspace, $newOwnerUserId, $previousOwnerDisposition) {
            $lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id);

            if ($lockedWorkspace === null) {
                throw new WorkspaceNotFoundException($workspace->id);
            }

            if (! $lockedWorkspace->is_active) {
                throw new InactiveWorkspaceMutationException($lockedWorkspace->id);
            }

            $this->assertActorIsOwner($actorUserId, $lockedWorkspace);

            $previousOwnerUserId = (int) $lockedWorkspace->owner_user_id;

            if ($newOwnerUserId === $previousOwnerUserId) {
                return $lockedWorkspace;
            }

            $userIdsToLock = collect([$previousOwnerUserId, $newOwnerUserId])->unique()->sort()->values();

            $lockedUsers = [];

            foreach ($userIdsToLock as $userId) {
                $lockedUsers[$userId] = $this->lockOwnerRow($userId);
            }

            if ($lockedUsers[$newOwnerUserId] === null) {
                throw new WorkspaceInvalidIncomingOwnerException($newOwnerUserId);
            }

            if ($lockedUsers[$previousOwnerUserId] === null) {
                throw new RuntimeException(
                    "Workspace [{$lockedWorkspace->id}]'s current owner User [{$previousOwnerUserId}] does not " .
                    'exist despite workspaces.owner_user_id being a foreign key to users.id.'
                );
            }

            $lockedMemberships = [];

            foreach ($userIdsToLock as $userId) {
                $lockedMemberships[$userId] = $this->membershipRepository->findByWorkspaceAndUserForUpdate($lockedWorkspace->id, $userId);
            }

            $incomingMembership = $lockedMemberships[$newOwnerUserId];
            $previousMembership = $lockedMemberships[$previousOwnerUserId];

            if ($incomingMembership !== null && $incomingMembership->is_active) {
                $this->membershipRepository->setActive($incomingMembership, false);

                WorkspaceMembershipDeactivated::dispatch($incomingMembership->id, $lockedWorkspace->id, $newOwnerUserId, $actorUserId);
            }

            $updatedWorkspace = $this->workspaceRepository->transferOwnership($lockedWorkspace, $newOwnerUserId);

            if ($previousOwnerDisposition->mode === WorkspaceOwnershipTransferMode::Deactivate) {
                $this->reconcileDeactivateDisposition($previousMembership, $actorUserId, $updatedWorkspace, $previousOwnerUserId);
            } else {
                $this->reconcileConvertToAdminDisposition($previousMembership, $previousOwnerDisposition, $actorUserId, $updatedWorkspace, $previousOwnerUserId);
            }

            $this->transitionRepository->create([
                'workspace_id' => $updatedWorkspace->id,
                'transition_type' => WorkspaceTransitionType::OwnershipTransferred,
                'actor_user_id' => $actorUserId,
                'from_owner_user_id' => $previousOwnerUserId,
                'to_owner_user_id' => $newOwnerUserId,
                'previous_owner_disposition' => $previousOwnerDisposition->mode->value,
                'business_id' => null,
                'from_workspace_id' => null,
            ]);

            WorkspaceOwnershipTransferred::dispatch(
                $updatedWorkspace->id,
                $actorUserId,
                $previousOwnerUserId,
                $newOwnerUserId,
                $previousOwnerDisposition->mode->value,
            );

            return $updatedWorkspace;
        });
    }

    /**
     * WorkspaceOwnershipTransferMode::Deactivate reconciliation. A missing
     * previous-owner membership is a valid, expected state (§7.3 — the
     * owner never held one) and requires no write. An existing active row
     * is deactivated, never deleted; its workspace_membership_businesses
     * rows are retained untouched. An existing already-inactive row is
     * left exactly as-is — no write, no event.
     */
    private function reconcileDeactivateDisposition(
        ?WorkspaceMembership $previousMembership,
        int $actorUserId,
        Workspace $workspace,
        int $previousOwnerUserId,
    ): void {
        if ($previousMembership === null || ! $previousMembership->is_active) {
            return;
        }

        $this->membershipRepository->setActive($previousMembership, false);

        WorkspaceMembershipDeactivated::dispatch($previousMembership->id, $workspace->id, $previousOwnerUserId, $actorUserId);
    }

    /**
     * WorkspaceOwnershipTransferMode::ConvertToAdmin reconciliation. When
     * no previous-owner membership exists, creates one exactly like
     * addMember() would (Created dispatched before any initial Assigned
     * grant, ascending) — no role/scope-change event for a brand-new row.
     * When one already exists (including the anomalous case of an already-
     * active owner-membership row, per §7.3's normal invariant not always
     * holding for historical data) it is reconciled in place: reactivated
     * if inactive, promoted to Admin if not already, then its Business-
     * access scope reconciled using the same normalized set-difference
     * approach as changeMemberBusinessAccessScope() — but with this
     * method's own required event order (scope-changed always dispatched
     * before assigned/unassigned here, unlike that method's `selected` ->
     * `all` branch), so this is deliberately not delegated to it.
     */
    private function reconcileConvertToAdminDisposition(
        ?WorkspaceMembership $previousMembership,
        WorkspaceOwnershipTransferDisposition $disposition,
        int $actorUserId,
        Workspace $workspace,
        int $previousOwnerUserId,
    ): void {
        if ($previousMembership === null) {
            $newMembership = $this->membershipRepository->create($workspace, $previousOwnerUserId, WorkspaceMembershipRole::Admin, $disposition->scope);

            if ($disposition->scope === WorkspaceBusinessAccessScope::Selected && $disposition->businessIds !== []) {
                $this->membershipBusinessRepository->syncForMembership($newMembership, $disposition->businessIds);
            }

            WorkspaceMembershipCreated::dispatch(
                $newMembership->id,
                $workspace->id,
                $previousOwnerUserId,
                $actorUserId,
                WorkspaceMembershipRole::Admin->value,
                $disposition->scope->value,
            );

            if ($disposition->scope === WorkspaceBusinessAccessScope::Selected) {
                foreach ($disposition->businessIds as $businessId) {
                    WorkspaceMembershipBusinessAssigned::dispatch($newMembership->id, $workspace->id, $businessId, $actorUserId);
                }
            }

            return;
        }

        if (! $previousMembership->is_active) {
            $this->membershipRepository->setActive($previousMembership, true);

            WorkspaceMembershipReactivated::dispatch($previousMembership->id, $workspace->id, $previousOwnerUserId, $actorUserId);
        }

        if ($previousMembership->role !== WorkspaceMembershipRole::Admin) {
            $previousRole = $previousMembership->role;
            $this->membershipRepository->updateRole($previousMembership, WorkspaceMembershipRole::Admin);

            WorkspaceMembershipRoleChanged::dispatch(
                $previousMembership->id,
                $workspace->id,
                $previousOwnerUserId,
                $actorUserId,
                $previousRole->value,
                WorkspaceMembershipRole::Admin->value,
            );
        }

        $currentScope = $previousMembership->business_access_scope;
        $currentIds = $this->normalizeBusinessIds(
            $this->membershipBusinessRepository->assignedBusinessIds($previousMembership)->all()
        );
        $requestedScope = $disposition->scope;
        $requestedIds = $disposition->businessIds;

        if ($currentScope === $requestedScope && $currentIds === $requestedIds) {
            return;
        }

        $this->membershipBusinessRepository->syncForMembership($previousMembership, $requestedIds);

        if ($currentScope !== $requestedScope) {
            $this->membershipRepository->updateBusinessAccessScope($previousMembership, $requestedScope);

            WorkspaceMembershipBusinessAccessScopeChanged::dispatch(
                $previousMembership->id,
                $workspace->id,
                $previousOwnerUserId,
                $actorUserId,
                $currentScope->value,
                $requestedScope->value,
            );
        }

        $added = $this->addedIds($currentIds, $requestedIds);
        $removed = $this->removedIds($currentIds, $requestedIds);

        foreach ($added as $businessId) {
            WorkspaceMembershipBusinessAssigned::dispatch($previousMembership->id, $workspace->id, $businessId, $actorUserId);
        }

        foreach ($removed as $businessId) {
            WorkspaceMembershipBusinessUnassigned::dispatch($previousMembership->id, $workspace->id, $businessId, $actorUserId);
        }
    }

    /**
     * Shared authority rule for addMember() (against the requested role)
     * and deactivateMember()/reactivateMember() (against the target
     * membership's current role) — Admin requires the Workspace owner;
     * every other role accepts the owner or an active Admin.
     */
    private function assertHasAuthorityOverRole(int $actorUserId, Workspace $workspace, WorkspaceMembershipRole $role): void
    {
        if ($role === WorkspaceMembershipRole::Admin) {
            $this->assertActorIsOwner($actorUserId, $workspace);

            return;
        }

        $this->assertActorIsOwnerOrActiveAdmin($actorUserId, $workspace);
    }

    /**
     * @param  array<int, mixed>  $businessIds
     * @return array<int, int>
     */
    private function normalizeBusinessIds(array $businessIds): array
    {
        return collect($businessIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * IDs present in $requested but not $current, in ascending order.
     * Both arguments are expected to already be normalizeBusinessIds()
     * output (deduplicated, ascending, zero-indexed) — array_diff()
     * preserves $requested's existing order, so no re-sort is needed.
     *
     * @param  array<int, int>  $current
     * @param  array<int, int>  $requested
     * @return array<int, int>
     */
    private function addedIds(array $current, array $requested): array
    {
        return array_values(array_diff($requested, $current));
    }

    /**
     * IDs present in $current but not $requested, in ascending order. Same
     * pre-normalized-input assumption as addedIds().
     *
     * @param  array<int, int>  $current
     * @param  array<int, int>  $requested
     * @return array<int, int>
     */
    private function removedIds(array $current, array $requested): array
    {
        return array_values(array_diff($current, $requested));
    }

    public function resolveLegacyOnboardingWorkspace(int $ownerUserId): Workspace
    {
        return DB::transaction(function () use ($ownerUserId) {
            $userRow = $this->lockOwnerRow($ownerUserId);

            if ($userRow === null) {
                throw (new ModelNotFoundException())->setModel(User::class, [$ownerUserId]);
            }

            $preferredIds = $this->collectPreferredCandidateIds($ownerUserId);

            if ($preferredIds->isNotEmpty()) {
                $workspaces = $this->verifyWorkspaceIds($ownerUserId, $preferredIds);

                if ($preferredIds->count() === 1) {
                    return $workspaces->first();
                }

                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    $preferredIds->all(),
                    WorkspaceContextFailureReason::MultiplePreferredCandidates
                );
            }

            $fallbackIds = $this->collectFallbackCandidateIds($ownerUserId);

            if ($fallbackIds->isEmpty()) {
                return $this->provisionWorkspaceRecord($ownerUserId, $userRow);
            }

            $workspaces = $this->verifyWorkspaceIds($ownerUserId, $fallbackIds);

            if ($fallbackIds->count() === 1) {
                return $workspaces->first();
            }

            throw new WorkspaceContextRequiredException(
                $ownerUserId,
                $fallbackIds->all(),
                WorkspaceContextFailureReason::MultipleFallbackCandidates
            );
        });
    }

    /**
     * The stable users row is the serialization point (RFC-003 §13.1 step
     * 2, §18) — there is no Workspace row to lock when none exists yet.
     * Protected so a test can hold this lock open for a controlled
     * duration after acquiring it, to prove a second concurrent attempt
     * genuinely blocks rather than completing sequentially by coincidence.
     */
    protected function lockOwnerRow(int $ownerUserId): ?object
    {
        return DB::table('users')->where('id', $ownerUserId)->lockForUpdate()->first();
    }

    /**
     * Onboarding-linked and primary Businesses are equally preferred
     * sources (§13.1) — neither is silently prioritized over the other.
     * Both contribute to one flat, deduplicated ID set.
     */
    private function collectPreferredCandidateIds(int $ownerUserId): Collection
    {
        $ids = collect();

        $onboarding = $this->onboardingRepository->findByCustomerId($ownerUserId);

        if ($onboarding !== null && $onboarding->business_id !== null) {
            $onboardingBusiness = $this->businessRepository->findById($onboarding->business_id);

            if ($onboardingBusiness === null) {
                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    [],
                    WorkspaceContextFailureReason::OnboardingBusinessReferenceInvalid
                );
            }

            if ((int) $onboardingBusiness->customer_id !== $ownerUserId) {
                throw new WorkspaceContextRequiredException(
                    $ownerUserId,
                    [],
                    WorkspaceContextFailureReason::OnboardingBusinessCustomerMismatch
                );
            }

            if ($onboardingBusiness->workspace_id !== null) {
                $ids->push((int) $onboardingBusiness->workspace_id);
            }
        }

        foreach ($this->businessRepository->primaryBusinessesForCustomer($ownerUserId) as $primaryBusiness) {
            if ($primaryBusiness->workspace_id !== null) {
                $ids->push((int) $primaryBusiness->workspace_id);
            }
        }

        return $ids->unique()->values();
    }

    /**
     * Business-linked Workspaces (source A) are valid candidates
     * regardless of Workspace ownership; directly-owned Workspaces
     * (source B) are candidates by ownership alone. No membership,
     * users.parent_id, plan, or unrelated-customer data is consulted.
     */
    private function collectFallbackCandidateIds(int $ownerUserId): Collection
    {
        $businessLinked = $this->businessRepository->workspaceIdsForCustomer($ownerUserId);

        $directlyOwned = $this->workspaceRepository->findOwnedBy($ownerUserId)
            ->map(fn (Workspace $workspace) => (int) $workspace->id);

        return $businessLinked->merge($directlyOwned)->unique()->values();
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function verifyWorkspaceIds(int $ownerUserId, Collection $ids): Collection
    {
        $missingIds = [];
        $workspaces = collect();

        foreach ($ids as $id) {
            $workspace = $this->workspaceRepository->findById($id);

            if ($workspace === null) {
                $missingIds[] = $id;

                continue;
            }

            $workspaces->push($workspace);
        }

        if ($missingIds !== []) {
            throw new WorkspaceContextRequiredException(
                $ownerUserId,
                $missingIds,
                WorkspaceContextFailureReason::DanglingWorkspaceReference
            );
        }

        return $workspaces;
    }

    /**
     * Renamed from createWorkspace() (RFC-003 Milestone 2 Slice 2C) to
     * avoid colliding with the new public createWorkspace() below — this
     * remains the legacy resolver's own narrow, deterministically-named
     * Workspace provisioning step, used only by
     * resolveLegacyOnboardingWorkspace(); its candidate selection,
     * locking, naming and exceptions are unchanged.
     */
    private function provisionWorkspaceRecord(int $ownerUserId, object $userRow): Workspace
    {
        return $this->workspaceRepository->create([
            'owner_user_id' => $ownerUserId,
            'name' => $this->resolveWorkspaceName($ownerUserId, $userRow),
            'is_active' => true,
        ]);
    }

    /**
     * Deterministic name priority (RFC-003 §10.5-equivalent), first
     * non-blank value wins: customers.company, then the single primary
     * Business's name (only when exactly one exists — two or more skip
     * this tier rather than picking one arbitrarily), then the first
     * Business by id, then "{first_name} {last_name}'s Workspace" from
     * the already-locked users row, then a customer_id-based fallback
     * that can never itself be blank.
     */
    private function resolveWorkspaceName(int $ownerUserId, object $userRow): string
    {
        $company = $this->customerRepository->findByUserId($ownerUserId)?->company;

        if ($this->isNonBlank($company)) {
            return trim($company);
        }

        $primaryBusinesses = $this->businessRepository->primaryBusinessesForCustomer($ownerUserId);

        if ($primaryBusinesses->count() === 1) {
            $primaryName = $primaryBusinesses->first()->name;

            if ($this->isNonBlank($primaryName)) {
                return trim($primaryName);
            }
        }

        $firstBusiness = $this->businessRepository->findFirstByCustomer($ownerUserId);

        if ($firstBusiness !== null && $this->isNonBlank($firstBusiness->name)) {
            return trim($firstBusiness->name);
        }

        $fullName = trim(($userRow->first_name ?? '') . ' ' . ($userRow->last_name ?? ''));

        if ($this->isNonBlank($fullName)) {
            return "{$fullName}'s Workspace";
        }

        return "Customer #{$ownerUserId}'s Workspace";
    }

    private function isNonBlank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
