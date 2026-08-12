<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Business\UpsertBusinessIdentityRequest;
use App\Http\Requests\Customer\Workspace\ReassignWorkspaceBusinessRequest;
use App\Http\Requests\Customer\Workspace\RenameWorkspaceRequest;
use App\Http\Requests\Customer\Workspace\StoreWorkspaceMemberRequest;
use App\Http\Requests\Customer\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Customer\Workspace\UpdateWorkspaceMemberAccessRequest;
use App\Http\Requests\Customer\Workspace\UpdateWorkspaceMemberRoleRequest;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class WorkspaceController extends CustomerBaseController
{
    private const ROLE_LABELS = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    /**
     * RFC-003 Milestone 3 Slice 3A: read-only Workspace switcher. Population
     * is exactly WorkspaceRepository::allForUser() (owner + active
     * membership, is_active-agnostic) — no Business query, no mutation, no
     * additional repository call beyond the one per-row membership reread
     * needed to resolve the effective role.
     */
    public function index(): View
    {
        $userId = (int) Auth::id();

        $workspaces = $this->workspaceRepository->allForUser($userId)
            ->map(fn (Workspace $workspace) => $this->presentationRow($workspace, $userId))
            ->filter()
            ->values();

        return view('customer.workspaces.index', ['workspaces' => $workspaces]);
    }

    /**
     * RFC-003 Milestone 3 Slice 3B/3C: read-only Workspace overview. 404
     * (never 403) for an unknown uid or a user with no owner/active-
     * membership path to this Workspace — owner status always wins over an
     * anomalous coexisting membership row. The embedded membership
     * directory is populated only for the owner and an active Admin; for
     * active Staff the `directory` key is omitted from the view data
     * entirely rather than rendered empty. `businesses` is always present
     * (owner, Admin, and Staff alike) and is the RFC-003 §14.1 effective-
     * access filter over this Workspace's Businesses via
     * WorkspaceManager::userCanAccessBusiness() — never a second,
     * partially reimplemented algorithm.
     */
    public function show(string $workspaceUid): View
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $userId = (int) Auth::id();
        $roleKey = $this->effectiveRoleKey($workspace, $userId);

        if ($roleKey === null) {
            abort(404);
        }

        $viewData = [
            'workspace' => [
                'name' => $workspace->name,
                'is_active' => (bool) $workspace->is_active,
                'role' => self::ROLE_LABELS[$roleKey],
            ],
            'businesses' => $this->effectiveBusinesses($workspace, $userId),
        ];

        if (in_array($roleKey, ['owner', 'admin'], true)) {
            $viewData['directory'] = $this->membershipDirectory($workspace);
            $viewData['manageableBusinesses'] = $this->manageableBusinesses($workspace, $userId);

            // RFC-003 Milestone 4 Slice 4E: UI-only transport for the
            // reassignment control's target-Workspace candidates -- a
            // request attribute, not a view-data key, so this Milestone 3
            // show() response's top-level shape (workspace, businesses,
            // directory, manageableBusinesses) stays exactly as it already
            // was for every existing caller/test.
            request()->attributes->set('reassignTargetWorkspaces', $this->manageableTargetWorkspaces($userId));
        }

        return view('customer.workspaces.show', $viewData);
    }

    /**
     * RFC-003 Milestone 4 Slice 4A: creates a Workspace owned by the
     * authenticated user via WorkspaceManager::createWorkspace(). No
     * Business is created here -- that remains outside this slice.
     */
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $workspace = $this->workspaceManager->createWorkspace(
            (int) Auth::id(),
            $request->validated('name'),
        );

        return redirect()
            ->route('customer.workspaces.show', $workspace->uid)
            ->with('flash_success', 'Workspace created.');
    }

    /**
     * Resolves the target by uid and delegates entirely to
     * WorkspaceManager::renameWorkspace() -- owner-or-active-admin
     * authority and the active-Workspace requirement are enforced there,
     * never reimplemented here.
     */
    public function rename(RenameWorkspaceRequest $request, string $workspaceUid): RedirectResponse
    {
        $userId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $userId);

        try {
            $this->workspaceManager->renameWorkspace($userId, $workspace, $request->validated('name'));
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to rename this Workspace.');
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be renamed.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Workspace renamed.');
    }

    /**
     * Owner-only Workspace deactivation. Resolves the target by uid and
     * delegates authority entirely to
     * WorkspaceManager::deactivateWorkspace() -- Staff and Admin never
     * gain deactivation authority here.
     */
    public function deactivate(string $workspaceUid): RedirectResponse
    {
        $userId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $userId);

        try {
            $this->workspaceManager->deactivateWorkspace($userId, $workspace);
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to deactivate this Workspace.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Workspace deactivated.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4C: owner-only Workspace reactivation.
     * Resolves the target by uid and delegates entirely to
     * WorkspaceManager::reactivateWorkspace() -- owner-only authority and
     * the idempotent no-op on an already-active Workspace are enforced
     * there, never reimplemented here. Mirrors deactivate() exactly.
     */
    public function reactivate(string $workspaceUid): RedirectResponse
    {
        $userId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $userId);

        try {
            $this->workspaceManager->reactivateWorkspace($userId, $workspace);
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to reactivate this Workspace.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Workspace reactivated.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4D: owner-or-active-Admin Business creation
     * inside an existing Workspace. Resolves the target Workspace by uid via
     * the existing resolveAccessibleWorkspace() pattern and delegates
     * entirely to WorkspaceManager::createBusinessInWorkspace() -- Workspace
     * mutation authority and the active-Workspace requirement are enforced
     * there, never reimplemented here. createBusinessInWorkspace() does not
     * infer or verify Customer ownership from the actor (RFC-003 §11.2): the
     * acting Customer is resolved here, exclusively from the authenticated
     * user's own User::customer() relationship, never from request input.
     * This is the slice's sole HTTP tenancy boundary, not a competing
     * Workspace authorization algorithm. Reuses the existing, unmodified
     * UpsertBusinessIdentityRequest -- its validated payload carries
     * Business identity fields only, no customer identifier of any kind.
     */
    public function storeBusiness(UpsertBusinessIdentityRequest $request, string $workspaceUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $customer = Auth::user()->customer;

        try {
            $this->workspaceManager->createBusinessInWorkspace($actorUserId, $customer, $workspace, $request->validated());
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to create a Business in this Workspace.');
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot receive a new Business.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Business created.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4E: reassigns an existing Business from this
     * (source) Workspace to a different (target) Workspace, through the
     * existing WorkspaceManager::reassignBusiness(). The source Workspace
     * and the target Workspace both use the existing
     * resolveAccessibleWorkspace() pattern -- reused twice, never a new
     * resolver. The Business is resolved by opaque uid scoped to the
     * source Workspace via resolveWorkspaceBusiness() -- addressability
     * only, never filtered through accessibleBusinesses(); the manager's
     * own assertUserCanAccessBusiness() call (added for this slice) remains
     * the sole authoritative Business-access decision, so a Business that
     * exists in the source Workspace but is outside the actor's access
     * still reaches the manager and is denied there, not pre-filtered here.
     * WorkspaceManager remains exclusively authoritative for owner-or-
     * active-Admin authority over both Workspaces, both-Workspace active
     * state, and the actor's Business access to the source Business --
     * none of that is reimplemented in this action.
     */
    public function reassignBusiness(ReassignWorkspaceBusinessRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $sourceWorkspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $business = $this->resolveWorkspaceBusiness($sourceWorkspace, $businessUid);
        $targetWorkspace = $this->resolveAccessibleWorkspace($request->validated('target_workspace_uid'), $actorUserId);

        try {
            $this->workspaceManager->reassignBusiness($actorUserId, $business, $targetWorkspace);
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to reassign this Business.');
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be involved in a Business reassignment.');
        } catch (WorkspaceAccessDeniedException|WorkspaceNotFoundException|WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Business reassigned.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4B: adds an existing User as an active
     * member via a nullable User uid lookup + WorkspaceManager::addMember() —
     * unknown user uid fails closed with 404, matching resolveAccessibleMembership()'s
     * unknown/inaccessible-target boundary. Business selection is resolved
     * and access-checked entirely by resolveManageableBusinessIds() before
     * any WorkspaceManager call, so an invalid selection never reaches the
     * manager and never partially writes; an invalid selection resolves to
     * the same 404 as an unauthorized actor or unknown target, not a
     * flash-message redirect, so this pre-check can't be used as an oracle
     * either. An UnauthorizedWorkspaceManagementException from the manager
     * itself also resolves to 404 for the same reason.
     */
    public function storeMember(StoreWorkspaceMemberRequest $request, string $workspaceUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);

        $targetUser = User::query()->where('uid', $request->validated('user_uid'))->first();

        if ($targetUser === null) {
            abort(404);
        }

        $role = WorkspaceMembershipRole::from($request->validated('role'));
        $scope = WorkspaceBusinessAccessScope::from($request->validated('business_access_scope'));
        $businessIds = [];

        if ($scope === WorkspaceBusinessAccessScope::Selected) {
            $businessIds = $this->resolveManageableBusinessIds($workspace, $actorUserId, $request->validated('business_uids', []));

            if ($businessIds === null) {
                abort(404);
            }
        } elseif (! $this->actorHasUnrestrictedBusinessAccess($workspace, $actorUserId)) {
            abort(404);
        }

        try {
            $this->workspaceManager->addMember($actorUserId, $workspace, (int) $targetUser->id, $role, $scope, $businessIds);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            $hasAuthorityOverRole = $role === WorkspaceMembershipRole::Admin
                ? $this->effectiveRoleKey($workspace, $actorUserId) === 'owner'
                : in_array($this->effectiveRoleKey($workspace, $actorUserId), ['owner', 'admin'], true);

            if (! $hasAuthorityOverRole) {
                abort(404);
            }

            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot receive new members.');
        } catch (OwnerCannotBeMemberException|WorkspaceMembershipAlreadyExistsException) {
            return redirect()->back()->with('flash_error', 'This user cannot be added as a member.');
        } catch (InvalidBusinessAccessScopeAssignmentException) {
            return redirect()->back()->with('flash_error', 'Business selections are not valid for the "All Businesses" scope.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member added.');
    }

    /**
     * Owner-only Admin promotion/demotion, owner-or-active-Admin otherwise
     * -- entirely WorkspaceManager::changeMemberRole()'s own authority
     * rule, never reimplemented here. An authority failure resolves to the
     * same 404 as an unknown memberUid, not a flash-message redirect.
     * changeMemberRole() itself checks the target membership's active
     * state before its own owner-only authority assertion, so an
     * unauthorized actor targeting an inactive membership must not see the
     * distinguishing flash-message redirect either -- effectiveRoleKey()
     * re-reads the exact same owner/active-Admin primitives the manager's
     * private assertion uses, not a second authorization rule, purely to
     * pick which response shape an already-thrown exception gets.
     */
    public function updateMemberRole(UpdateWorkspaceMemberRoleRequest $request, string $workspaceUid, string $memberUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $membership = $this->resolveAccessibleMembership($workspace, $memberUid);

        $role = WorkspaceMembershipRole::from($request->validated('role'));

        try {
            $this->workspaceManager->changeMemberRole($actorUserId, $membership, $role);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be managed.');
        } catch (InactiveWorkspaceMembershipMutationException) {
            if ($this->effectiveRoleKey($workspace, $actorUserId) !== 'owner') {
                abort(404);
            }

            return redirect()->back()->with('flash_error', 'An inactive member must be reactivated before its role can change.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member role updated.');
    }

    /**
     * Owner-or-active-Admin Business-access scope/assignment change --
     * entirely WorkspaceManager::changeMemberBusinessAccessScope()'s own
     * authority and synchronization rules. Same pre-validated,
     * fail-closed Business resolution as storeMember(): an invalid
     * selection resolves to the same 404 as an unknown memberUid or an
     * authority failure, never a flash-message redirect, so neither
     * pre-check can be used as a target-existence oracle.
     * changeMemberBusinessAccessScope() itself checks the target
     * membership's active state before its own owner-or-active-Admin
     * authority assertion, so an unauthorized actor targeting an inactive
     * membership must not see the distinguishing flash-message redirect
     * either -- effectiveRoleKey() re-reads the exact same owner/
     * active-Admin primitives the manager's private assertion uses, not a
     * second authorization rule, purely to pick which response shape an
     * already-thrown exception gets.
     */
    public function updateMemberAccess(UpdateWorkspaceMemberAccessRequest $request, string $workspaceUid, string $memberUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $membership = $this->resolveAccessibleMembership($workspace, $memberUid);

        $scope = WorkspaceBusinessAccessScope::from($request->validated('business_access_scope'));
        $businessIds = [];

        if ($scope === WorkspaceBusinessAccessScope::Selected) {
            $businessIds = $this->resolveManageableBusinessIds($workspace, $actorUserId, $request->validated('business_uids', []));

            if ($businessIds === null) {
                abort(404);
            }
        } elseif (! $this->actorHasUnrestrictedBusinessAccess($workspace, $actorUserId)) {
            abort(404);
        }

        try {
            $this->workspaceManager->changeMemberBusinessAccessScope($actorUserId, $membership, $scope, $businessIds);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be managed.');
        } catch (InactiveWorkspaceMembershipMutationException) {
            if (! in_array($this->effectiveRoleKey($workspace, $actorUserId), ['owner', 'admin'], true)) {
                abort(404);
            }

            return redirect()->back()->with('flash_error', 'An inactive member must be reactivated before its Business access can change.');
        } catch (InvalidBusinessAccessScopeAssignmentException) {
            return redirect()->back()->with('flash_error', 'Business selections are not valid for the "All Businesses" scope.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member Business access updated.');
    }

    /**
     * Deactivates one membership -- target-role authority (owner for Admin
     * targets, owner-or-active-Admin for Staff) is entirely
     * WorkspaceManager::deactivateMember()'s own rule. Every scoped
     * Business assignment row is retained by the manager, never touched
     * here. An authority failure resolves to the same 404 as an unknown
     * memberUid.
     */
    public function deactivateMember(string $workspaceUid, string $memberUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $membership = $this->resolveAccessibleMembership($workspace, $memberUid);

        try {
            $this->workspaceManager->deactivateMember($actorUserId, $membership);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be managed.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member deactivated.');
    }

    /**
     * Reactivates one membership -- same target-role authority rule as
     * deactivateMember(). WorkspaceManager::reactivateMember() restores
     * effective access purely by flipping is_active back to true; no
     * assignment-row restoration happens here. An authority failure
     * resolves to the same 404 as an unknown memberUid.
     */
    public function reactivateMember(string $workspaceUid, string $memberUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $membership = $this->resolveAccessibleMembership($workspace, $memberUid);

        try {
            $this->workspaceManager->reactivateMember($actorUserId, $membership);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot be managed.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member reactivated.');
    }

    /**
     * Same owner-or-active-membership visibility boundary as show()'s
     * effectiveRoleKey(): a uid that doesn't resolve, or that resolves to
     * a Workspace the actor has no owner/active-membership relationship
     * to, fails closed with 404 -- identical to show()'s unrelated-user
     * behavior -- so a mutation route can never be used to probe for
     * Workspace existence. This is purely the same visibility check
     * show() already makes; it never grants or narrows mutation
     * authority, which stays exclusively WorkspaceManager's job.
     */
    private function resolveAccessibleWorkspace(string $workspaceUid, int $userId): Workspace
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || $this->effectiveRoleKey($workspace, $userId) === null) {
            abort(404);
        }

        return $workspace;
    }

    /**
     * RFC-003 Milestone 4 Slice 4B: resolves a membership-management target
     * by the target User's opaque uid rather than a raw membership or user
     * ID. An unknown uid, or a User with no WorkspaceMembership row (active
     * or inactive) in this Workspace, fails closed with 404 -- identical
     * treatment for both cases so this route can never be used to probe
     * for a hidden target's existence. Deliberately returns an inactive
     * membership too: reactivateMember() is a legitimate caller. The
     * current Workspace owner is never a valid mutation target here even
     * if a retained membership row exists for them (transferOwnership()
     * deliberately keeps a prior membership row, deactivated, rather than
     * deleting it) -- same fail-closed 404 as an unknown uid, so that
     * retained row can never be targeted through this route either.
     */
    private function resolveAccessibleMembership(Workspace $workspace, string $memberUid): WorkspaceMembership
    {
        $targetUser = User::query()->where('uid', $memberUid)->first();

        if ($targetUser === null || (int) $targetUser->id === (int) $workspace->owner_user_id) {
            abort(404);
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, (int) $targetUser->id);

        if ($membership === null) {
            abort(404);
        }

        return $membership;
    }

    /**
     * RFC-003 Milestone 4 Slice 4E: resolves the Business targeted for
     * reassignment by opaque uid, scoped to the source Workspace via the
     * existing raw businessesForWorkspace() -- addressability only, never
     * filtered through accessibleBusinesses(). An unknown uid, or a uid
     * belonging to a different Workspace, both fail closed identically
     * with 404, mirroring resolveAccessibleMembership()'s exact pattern,
     * so this route can never be used to probe for a Business's existence
     * or true Workspace. The actor's actual Business-access authorization
     * remains exclusively WorkspaceManager::assertUserCanAccessBusiness()'s
     * job, called inside reassignBusiness() itself -- a Business that
     * exists here but is outside the actor's access still reaches the
     * manager and is denied there (WorkspaceAccessDeniedException, mapped
     * to the same 404), so this method must never pre-filter by access.
     */
    private function resolveWorkspaceBusiness(Workspace $workspace, string $businessUid): Business
    {
        $business = $this->workspaceRepository->businessesForWorkspace($workspace)
            ->firstWhere('uid', $businessUid);

        if ($business === null) {
            abort(404);
        }

        return $business;
    }

    /**
     * RFC-003 Milestone 4 Slice 4B: resolves submitted Business uids to IDs
     * for addMember()/changeMemberBusinessAccessScope(), reusing the exact
     * RFC-003 §14.1 effective-access filter (accessibleBusinesses()) rather
     * than a second algorithm -- an Admin with selected scope can only
     * select from their own effective access, identical to what
     * manageableBusinesses() shows them. An unknown, cross-Workspace, or
     * inaccessible uid, or an unresolvable uid, returns null so the caller
     * fails closed before any WorkspaceManager call -- no partial write.
     * Duplicate submitted uids are rejected by the Form Request's
     * `distinct` rule before this method ever runs.
     *
     * @param  array<int, string>  $businessUids
     * @return array<int, int>|null
     */
    private function resolveManageableBusinessIds(Workspace $workspace, int $actorUserId, array $businessUids): ?array
    {
        if ($businessUids === []) {
            return [];
        }

        $manageable = $this->accessibleBusinesses($workspace, $actorUserId)->keyBy('uid');
        $businessIds = [];

        foreach ($businessUids as $businessUid) {
            $business = $manageable->get($businessUid);

            if ($business === null) {
                return null;
            }

            $businessIds[] = (int) $business->id;
        }

        return $businessIds;
    }

    /**
     * Whether $actorUserId can grant `all`-scope Business access to
     * another member without exceeding their own effective access. The
     * Workspace owner always can (§7.3, never scope-limited). A
     * selected-scope Admin cannot: granting `all` would hand the target
     * access to every current and future Workspace Business, including
     * ones outside the Admin's own effective set -- the same principle
     * resolveManageableBusinessIds() already applies to individual
     * selected-scope grants. Only an active Admin whose own membership is
     * itself `all`-scope qualifies.
     */
    private function actorHasUnrestrictedBusinessAccess(Workspace $workspace, int $actorUserId): bool
    {
        if ((int) $workspace->owner_user_id === $actorUserId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $actorUserId);

        return $membership !== null
            && $membership->is_active
            && $membership->business_access_scope === WorkspaceBusinessAccessScope::All;
    }

    /**
     * RFC-003 §14.1's effective-access filter over this Workspace's
     * Businesses, ordered by businesses.id ascending -- the single shared
     * source for both effectiveBusinesses() (display-only, Milestone 3
     * Slice 3C) and manageableBusinesses()/resolveManageableBusinessIds()
     * (Milestone 4 Slice 4B management surfaces), so there is never a
     * second, independently-filtered Business set.
     *
     * @return Collection<int, Business>
     */
    private function accessibleBusinesses(Workspace $workspace, int $userId): Collection
    {
        return $this->workspaceRepository->businessesForWorkspace($workspace)
            ->filter(fn (Business $business) => $this->workspaceManager->userCanAccessBusiness($userId, $business))
            ->sortBy('id')
            ->values();
    }

    /**
     * RFC-003 Milestone 3 Slice 3C Business list: every Business in this
     * Workspace for which userCanAccessBusiness() returns true, sorted by
     * the persisted businesses.id ascending for a deterministic list.
     * Never exposes that numeric ID — each row carries only `name`.
     *
     * @return array<int, array{name: string}>
     */
    private function effectiveBusinesses(Workspace $workspace, int $userId): array
    {
        return $this->accessibleBusinesses($workspace, $userId)
            ->map(fn (Business $business) => ['name' => $business->name])
            ->all();
    }

    /**
     * RFC-003 Milestone 4 Slice 4B: the same effective-access Business set
     * as effectiveBusinesses(), but carrying each Business's opaque uid so
     * an owner/active-Admin manager can select a Business to assign on the
     * add-member/change-access forms. Never exposes the numeric ID.
     *
     * @return array<int, array{uid: string, name: string}>
     */
    private function manageableBusinesses(Workspace $workspace, int $userId): array
    {
        return $this->accessibleBusinesses($workspace, $userId)
            ->map(fn (Business $business) => ['uid' => $business->uid, 'name' => $business->name])
            ->all();
    }

    /**
     * RFC-003 Milestone 4 Slice 4E: candidate target Workspaces for the
     * Business-reassignment control -- every Workspace this actor can see
     * (WorkspaceRepository::allForUser(), already used by index()),
     * filtered to rows where the existing effectiveRoleKey() resolves to
     * owner or admin. Reuses existing primitives only; not a new
     * algorithm. UI convenience only -- a stale or hand-crafted
     * target_workspace_uid outside this list is still independently and
     * correctly enforced by resolveAccessibleWorkspace() and
     * WorkspaceManager at submission time.
     *
     * @return array<int, array{uid: string, name: string}>
     */
    private function manageableTargetWorkspaces(int $actorUserId): array
    {
        return $this->workspaceRepository->allForUser($actorUserId)
            ->filter(fn (Workspace $workspace) => in_array($this->effectiveRoleKey($workspace, $actorUserId), ['owner', 'admin'], true))
            ->map(fn (Workspace $workspace) => ['uid' => $workspace->uid, 'name' => $workspace->name])
            ->values()
            ->all();
    }

    /**
     * Same owner-wins-over-membership precedence as presentationRow(), but
     * returns only the role key (no uid/name row shape) since show() needs
     * just the effective role to decide access and directory visibility.
     */
    private function effectiveRoleKey(Workspace $workspace, int $userId): ?string
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return 'owner';
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return null;
        }

        return $membership->role === WorkspaceMembershipRole::Admin ? 'admin' : 'staff';
    }

    /**
     * Every membership row (active and inactive alike, via
     * WorkspaceMembershipRepository::allForWorkspace() -- RFC-003
     * Milestone 4 Slice 4B), ordered by workspace_memberships.id
     * ascending; the owner is never synthesized as a row. Inactive rows
     * are deliberately included, not omitted, so an owner/active-Admin
     * manager can find and reactivate them -- there is no separate
     * members-index surface. Each row's assigned-Business count is the
     * real workspace_membership_businesses row count for that membership,
     * not assumed from the access-scope label, and is retained (not
     * zeroed) across deactivation. `uid` is the member User's opaque uid
     * (never the numeric membership or user ID), used to target the
     * role/access/deactivate/reactivate actions. `assigned_business_uids`
     * carries the same assignment rows as `assigned_business_count`, but as
     * opaque Business uids, so the manager view can pre-check a member's
     * currently-assigned Businesses on the access-change form instead of
     * defaulting every checkbox to unchecked and silently clearing the
     * assignment set on an unmodified submit. transferOwnership() can
     * leave the current owner with their own retained (deactivated)
     * membership row rather than deleting it; that row is filtered out
     * here by owner_user_id so the owner is never listed as, or
     * reactivatable as, one of their own Workspace's members.
     *
     * @return array<int, array{uid: string, name: string, role: string, scope: string, assigned_business_count: int, assigned_business_uids: array<int, string>, is_active: bool}>
     */
    private function membershipDirectory(Workspace $workspace): array
    {
        $businessUidsById = $this->workspaceRepository->businessesForWorkspace($workspace)->pluck('uid', 'id');
        $ownerUserId = (int) $workspace->owner_user_id;

        return $this->membershipRepository->allForWorkspace($workspace)
            ->reject(fn (WorkspaceMembership $membership) => (int) $membership->user_id === $ownerUserId)
            ->sortBy('id')
            ->values()
            ->map(function (WorkspaceMembership $membership) use ($businessUidsById) {
                $assignedBusinessIds = $this->membershipBusinessRepository->assignedBusinessIds($membership);

                return [
                    'uid' => $membership->user->uid,
                    'name' => trim($membership->user->first_name . ' ' . $membership->user->last_name),
                    'role' => $membership->role === WorkspaceMembershipRole::Admin ? 'Admin' : 'Staff',
                    'scope' => $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                        ? 'All Businesses'
                        : 'Selected Businesses',
                    'assigned_business_count' => $assignedBusinessIds->count(),
                    'assigned_business_uids' => $assignedBusinessIds
                        ->map(fn (int $businessId) => $businessUidsById->get($businessId))
                        ->filter()
                        ->values()
                        ->all(),
                    'is_active' => (bool) $membership->is_active,
                ];
            })
            ->all();
    }

    /**
     * Owner always wins over an anomalous coexisting membership row (§7.3).
     * For a non-owner Workspace, the membership is reread rather than
     * trusted from allForUser()'s existence alone; a membership that has
     * gone missing or inactive between the two reads fails closed by
     * omitting the row (returns null), never by guessing a role.
     *
     * @return array{uid: string, name: string, is_active: bool, role: string}|null
     */
    private function presentationRow(Workspace $workspace, int $userId): ?array
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return $this->row($workspace, 'owner');
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return null;
        }

        $role = $membership->role === WorkspaceMembershipRole::Admin ? 'admin' : 'staff';

        return $this->row($workspace, $role);
    }

    /**
     * @return array{uid: string, name: string, is_active: bool, role: string}
     */
    private function row(Workspace $workspace, string $roleKey): array
    {
        return [
            'uid' => $workspace->uid,
            'name' => $workspace->name,
            'is_active' => (bool) $workspace->is_active,
            'role' => self::ROLE_LABELS[$roleKey],
        ];
    }
}
