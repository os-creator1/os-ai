<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\DTO\Workspace\WorkspaceOwnershipTransferDisposition;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceInvalidIncomingOwnerException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Business\UpsertBusinessIdentityRequest;
use App\Http\Requests\Customer\Workspace\ReassignWorkspaceBusinessRequest;
use App\Http\Requests\Customer\Workspace\RenameWorkspaceRequest;
use App\Http\Requests\Customer\Workspace\StoreWorkspaceMemberRequest;
use App\Http\Requests\Customer\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Customer\Workspace\TransferWorkspaceOwnershipRequest;
use App\Http\Requests\Customer\Workspace\UpdateWorkspaceMemberAccessRequest;
use App\Http\Requests\Customer\Workspace\UpdateWorkspaceMemberRoleRequest;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\BusinessFeatureSettings;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Usage\BillingProfileManager;
use App\Library\Workspace\AccountFrameAccess;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorkspaceController extends CustomerBaseController
{
    private const ROLE_LABELS = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];

    /**
     * One message for every "this person can't be added" outcome — no account
     * for that address, an account that isn't an active customer account, the
     * account's owner, or someone already on it — so the answer never tells an
     * account manager which of those it was.
     */
    private const MEMBER_CANNOT_BE_ADDED = 'We couldn\'t add that person. Check the email address: they need an existing Business OS account, and can\'t already be on this account or be its owner.';

    private const FEATURE_SWITCH_NOT_ALLOWED = 'You don\'t have permission to change this Business\'s features.';

    private const FEATURE_SWITCH_ACCOUNT_INACTIVE = 'This account is inactive, so its features can\'t be changed.';

    /**
     * The manager refuses to turn off a feature the Business cannot use right
     * now — one the plan leaves out, or one already turned off in another tab
     * — so the message covers both honestly.
     */
    private const FEATURE_SWITCH_NOT_AVAILABLE = 'This feature can\'t be changed right now. Refresh the page to see its current setting.';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BillingProfileManager $billingProfileManager,
    ) {
    }

    /**
     * RFC-003 Milestone 3 Slice 3A: read-only Workspace switcher. Population
     * is exactly WorkspaceRepository::allForUser() (owner + active
     * membership, is_active-agnostic) — no Business query, no mutation, no
     * additional repository call beyond the one per-row membership reread
     * needed to resolve the effective role.
     *
     * A chooser only where there is a choice: someone with one account goes
     * straight to it, several (their own plus invited memberships) get the
     * list, and only someone who owns no account at all is offered to create
     * their first one — never a second one (see store()).
     */
    public function index(): View|RedirectResponse
    {
        $userId = (int) Auth::id();
        $workspaces = $this->accountChoices($userId);

        if ($workspaces->count() === 1) {
            return redirect()->route('customer.workspaces.show', $workspaces->first()['uid']);
        }

        // The first-account form is for someone with nothing yet: no account
        // of their own and no membership anywhere (an invited client is not
        // offered to start a separate account here).
        return view('customer.workspaces.index', [
            'workspaces' => $workspaces,
            'canCreateFirstAccount' => $workspaces->isEmpty() && $this->workspaceRepository->allForUser($userId)->isEmpty(),
        ]);
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
        // Slice 1B Correction Round 1: the overview is the account frame, so
        // a selected-scope membership resolves to no role here (404) while
        // every mutation keeps its own, unchanged authorization.
        $roleKey = $this->effectiveRoleKey($workspace, $userId, accountFrameOnly: true);

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
            $viewData['entitlement'] = $this->entitlementViewData($workspace, $userId);
            $viewData['directory'] = $this->membershipDirectory($workspace);
            $viewData['manageableBusinesses'] = $this->manageableBusinesses($workspace, $userId);

            // Customer Experience Slice 5, Correction Round 1 §8 — the Agency
            // payer control lives here (Client accounts → [Business] →
            // Billing responsibility), for the Agency owner or an active
            // Agency-wide Admin only; the account frame already admits only
            // scope-all Admins. Core/Growth accounts never receive the key.
            $billingResponsibility = $this->billingResponsibilityViewData($workspace, $userId);

            if ($billingResponsibility !== null) {
                $viewData['billingResponsibility'] = $billingResponsibility;
            }
        }

        // UI-only, so the view-data shape stays as it is: the way back to
        // the account chooser is offered only when index() would show one.
        request()->attributes->set('showsAccountChooser', $this->accountChoices($userId)->count() > 1);

        return view('customer.workspaces.show', $viewData);
    }

    /**
     * Presentation facts only (uid, name, customer-facing responsibility);
     * never a payer enum, an assignment id or a user id. Null unless the
     * Workspace is on the Agency tier and the actor may change billing
     * responsibility for every listed client account. The Agency's own
     * directly-owned Business has no separate client to bill and is not
     * listed. Read-only: nothing is written on GET.
     *
     * @return array{businesses: list<array{uid: string, name: string, responsibility: string}>}|null
     */
    private function billingResponsibilityViewData(Workspace $workspace, int $userId): ?array
    {
        if ($this->entitlementManager->getWorkspaceEntitlementSummary($workspace)->tier !== WorkspacePlanTier::Agency) {
            return null;
        }

        $rows = [];

        foreach ($this->accessibleBusinesses($workspace, $userId) as $business) {
            if ((int) $business->customer_id === (int) $workspace->owner_user_id) {
                continue;
            }

            $facts = $this->billingProfileManager->billingResponsibilityFor($business, $userId);

            if (! $facts['actor_manages_responsibility']) {
                return null;
            }

            $rows[] = [
                'uid' => (string) $business->uid,
                'name' => (string) $business->name,
                'responsibility' => $facts['payer_type'] === 'workspace' ? 'agency' : 'client',
            ];
        }

        return ['businesses' => $rows];
    }

    /**
     * RFC-003 Milestone 4 Slice 4A: creates a Workspace owned by the
     * authenticated user via WorkspaceManager::createWorkspace(). No
     * Business is created here -- that remains outside this slice.
     *
     * Customer boundary: this creates a customer's own FIRST account only
     * (the zero-account bootstrap/recovery path). A customer has one account
     * — Core/Growth hold one Business, Agency client accounts are Businesses
     * inside it — so anyone who already owns a Workspace, active or not, is
     * sent back to it and nothing is created; a hand-made POST cannot do what
     * the page no longer offers. Invited memberships are not ownership. The
     * owner's users row is locked first (the same lock createWorkspace()
     * takes), so two simultaneous requests cannot both pass the check.
     * createWorkspace() stays the unrestricted domain capability for
     * platform/internal provisioning.
     */
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $userId = (int) Auth::id();

        $workspace = DB::transaction(function () use ($userId, $request) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            if ($this->workspaceRepository->findOwnedBy($userId)->isNotEmpty()) {
                return null;
            }

            return $this->workspaceManager->createWorkspace($userId, $request->validated('name'));
        });

        if ($workspace === null) {
            $ownedWorkspace = $this->workspaceRepository->findOwnedBy($userId)->sortBy('id')->first();

            return redirect()
                ->route('customer.workspaces.show', $ownedWorkspace->uid)
                ->with('flash_error', 'You already have an account.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspace->uid)
            ->with('flash_success', 'Account created.');
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
            return redirect()->back()->with('flash_error', 'You don\'t have permission to rename this account.');
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'This account is inactive, so it can\'t be renamed.');
        }

        // Shown as the page's compact "Saved" toast, not a full-width alert.
        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Saved');
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
        } catch (WorkspacePlanUnassignedException) {
            return redirect()->back()->with('flash_error', 'This Workspace has no plan assigned yet; contact support.');
        } catch (InactiveWorkspacePlanException) {
            return redirect()->back()->with('flash_error', 'This Workspace plan is currently inactive.');
        } catch (SuspendedWorkspacePlanException) {
            return redirect()->back()->with('flash_error', 'This Workspace plan is currently suspended.');
        } catch (BusinessSlotAllocationRequiredException) {
            return redirect()->back()->with('flash_error', 'This Workspace needs an additional Business slot allocated before another Business can be created.');
        } catch (BusinessSlotLimitExceededException) {
            return redirect()->back()->with('flash_error', 'This Workspace has reached its Business slot limit.');
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
        } catch (WorkspacePlanUnassignedException) {
            return redirect()->back()->with('flash_error', 'The target Workspace has no plan assigned yet; contact support.');
        } catch (InactiveWorkspacePlanException) {
            return redirect()->back()->with('flash_error', 'The target Workspace plan is currently inactive.');
        } catch (SuspendedWorkspacePlanException) {
            return redirect()->back()->with('flash_error', 'The target Workspace plan is currently suspended.');
        } catch (BusinessSlotAllocationRequiredException) {
            return redirect()->back()->with('flash_error', 'The target Workspace needs an additional Business slot allocated before this Business can be reassigned there.');
        } catch (BusinessSlotLimitExceededException) {
            return redirect()->back()->with('flash_error', 'The target Workspace has reached its Business slot limit.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Business reassigned.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4F: exposes the existing
     * WorkspaceManager::transferOwnership() through the customer HTTP
     * layer. Uses the existing resolveAccessibleWorkspace() for
     * addressability only -- transferOwnership()'s own
     * assertActorIsOwner() remains the sole authority, stricter than every
     * other Workspace mutation here (no active-Admin bypass). The incoming
     * owner is resolved by opaque uid exactly like storeMember()'s
     * target-User lookup; an unknown uid fails closed with 404 before the
     * manager is ever called, and the incoming User is not required to
     * already be a Workspace member. The previous-owner disposition is
     * constructed explicitly from validated input via the existing DTO's
     * two named factories -- never a third or default state. Selected-scope
     * Business uids reuse resolveManageableBusinessIds() unmodified: the
     * actor is always the Workspace owner here, and an owner always has
     * unconditional Business access under RFC-003 §14.1, so this naturally
     * resolves against the owner's full effective set with no relaxed
     * check. A successful transfer redirects to the Workspace index, not
     * this Workspace's own overview, because a deactivate disposition can
     * immediately strip the acting owner's own access to it.
     */
    public function transferOwnership(TransferWorkspaceOwnershipRequest $request, string $workspaceUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);

        $newOwner = User::query()->where('uid', $request->validated('new_owner_user_uid'))->first();

        if ($newOwner === null) {
            abort(404);
        }

        if ($request->validated('previous_owner_disposition') === 'deactivate') {
            $disposition = WorkspaceOwnershipTransferDisposition::deactivate();
        } else {
            $scope = WorkspaceBusinessAccessScope::from($request->validated('business_access_scope'));
            $businessIds = [];

            if ($scope === WorkspaceBusinessAccessScope::Selected) {
                $businessIds = $this->resolveManageableBusinessIds($workspace, $actorUserId, $request->validated('business_uids', []));

                if ($businessIds === null) {
                    abort(404);
                }
            }

            $disposition = WorkspaceOwnershipTransferDisposition::convertToAdmin($scope, $businessIds);
        }

        try {
            $this->workspaceManager->transferOwnership($actorUserId, $workspace, (int) $newOwner->id, $disposition);
        } catch (UnauthorizedWorkspaceManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to transfer ownership of this Workspace.');
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot have its ownership transferred.');
        } catch (WorkspaceNotFoundException|WorkspaceInvalidIncomingOwnerException|CrossWorkspaceAssignmentException) {
            abort(404);
        }

        return redirect()
            ->route('customer.workspaces.index')
            ->with('flash_success', 'Workspace ownership transferred.');
    }

    /**
     * RFC-003 Milestone 4 Slice 4B: adds an existing User as an active
     * member through WorkspaceManager::addMember(). The person is identified
     * by EMAIL ADDRESS (resolved here, server-side; only the numeric id goes
     * to the manager), never by an internal User uid.
     *
     * Order matters, so nothing about an address is revealed to an actor who
     * may not add members:
     *  1. the Workspace and the actor's standing in it (unknown or
     *     inaccessible → 404, unchanged);
     *  2. the actor's authority over the requested role — the manager's own
     *     rule, mirrored read-only — BEFORE the address is looked at (no
     *     authority → 404, the same answer as before);
     *  3. the Business selection (invalid → 404, unchanged, so it can't be
     *     used as an oracle either);
     *  4. only then the address: an unknown or ineligible address, the
     *     owner, or an existing member → back to this page with one generic
     *     message on the email field. The manager stays authoritative and
     *     fail-closed; UnauthorizedWorkspaceManagementException is still 404.
     */
    public function storeMember(StoreWorkspaceMemberRequest $request, string $workspaceUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);

        $role = WorkspaceMembershipRole::from($request->validated('role'));
        $scope = WorkspaceBusinessAccessScope::from($request->validated('business_access_scope'));

        if (! $this->hasAuthorityOverRole($workspace, $actorUserId, $role)) {
            abort(404);
        }

        $businessIds = [];

        if ($scope === WorkspaceBusinessAccessScope::Selected) {
            $businessIds = $this->resolveManageableBusinessIds($workspace, $actorUserId, $request->validated('business_uids', []));

            if ($businessIds === null) {
                abort(404);
            }
        } elseif (! $this->actorHasUnrestrictedBusinessAccess($workspace, $actorUserId)) {
            abort(404);
        }

        $targetUser = $this->findAddableUserByEmail((string) $request->validated('member_email'));

        if ($targetUser === null) {
            return $this->memberCannotBeAdded($workspaceUid);
        }

        try {
            $this->workspaceManager->addMember($actorUserId, $workspace, (int) $targetUser->id, $role, $scope, $businessIds);
        } catch (UnauthorizedWorkspaceManagementException) {
            abort(404);
        } catch (InactiveWorkspaceMutationException) {
            return redirect()->back()->with('flash_error', 'An inactive Workspace cannot receive new members.');
        } catch (OwnerCannotBeMemberException|WorkspaceMembershipAlreadyExistsException) {
            return $this->memberCannotBeAdded($workspaceUid);
        } catch (InvalidBusinessAccessScopeAssignmentException) {
            return redirect()->back()->with('flash_error', 'Business selections are not valid for the "All Businesses" scope.');
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Member added.');
    }

    /**
     * WorkspaceManager::addMember()'s own authority rule, mirrored read-only
     * so it can be checked before anything else: adding an Admin needs the
     * owner; adding Staff needs the owner or an active Admin. The manager
     * still enforces it on write.
     */
    private function hasAuthorityOverRole(Workspace $workspace, int $actorUserId, WorkspaceMembershipRole $role): bool
    {
        $effectiveRole = $this->effectiveRoleKey($workspace, $actorUserId);

        return $role === WorkspaceMembershipRole::Admin
            ? $effectiveRole === 'owner'
            : in_array($effectiveRole, ['owner', 'admin'], true);
    }

    /**
     * The active customer account with this email address, matched exactly
     * as sign-in matches it (the same `users.email` equality, so the same
     * case-insensitive collation). Anything else — no account, a disabled
     * account, a platform-only account — is null.
     */
    private function findAddableUserByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', trim($email))
            ->where('is_customer', true)
            ->where('status', true)
            ->first();
    }

    private function memberCannotBeAdded(string $workspaceUid): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->withErrors(['member_email' => self::MEMBER_CANNOT_BE_ADDED])
            ->withInput(['member_email' => (string) request()->input('member_email')]);
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
     * RFC-004 Milestone 3 §12: Owner/active-Admin-only plan/capacity/
     * per-Business feature view data, assembled entirely from
     * EntitlementManager's own presentation API (§8) -- never a repository
     * read here. One decideAvailableFeaturesForBusiness() call per Business
     * already shown by effectiveBusinesses() for this role. `featureSettings`
     * is the customer's switch list built from those same decisions: only
     * the features BusinessFeatureSettings says a customer can see are ever
     * sent to the page.
     *
     * @return array{summary: \App\Library\Entitlement\WorkspaceEntitlementSummary, features: array<string, array<string, array{decision: \App\Library\Entitlement\EntitlementDecision, disablePreferenceRecorded: bool}>>, featureSettings: array<string, list<array{key: string, name: string, description: string, enabled: bool}>>}
     */
    private function entitlementViewData(Workspace $workspace, int $userId): array
    {
        $features = [];
        $featureSettings = [];

        foreach ($this->accessibleBusinesses($workspace, $userId) as $business) {
            $features[$business->uid] = $this->entitlementManager->decideAvailableFeaturesForBusiness($workspace, $business, $userId);
            $featureSettings[$business->uid] = BusinessFeatureSettings::fromDecisions($features[$business->uid]);
        }

        return [
            'summary' => $this->entitlementManager->getWorkspaceEntitlementSummary($workspace),
            'features' => $features,
            'featureSettings' => $featureSettings,
        ];
    }

    /**
     * RFC-004 Milestone 3 §12/§13: turns a currently-entitled feature off for
     * one Business (records its disable preference). EntitlementManager stays
     * the only authority: it checks the actor, the Workspace and the
     * entitlement. The account page's switches call this with
     * `Accept: application/json` and get the saved state back; any other
     * request keeps the redirect.
     */
    public function disableBusinessFeature(string $workspaceUid, string $businessUid, string $featureKey): RedirectResponse|JsonResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $feature = PlatformFeature::tryFrom($featureKey);

        // Correction 2 — a Workspace-scoped feature (ProspectOutreach) is
        // not a valid target for this Business-feature-toggle surface at
        // all, and must fail identically to an unknown feature key —
        // never a distinguishable response that would let this route be
        // used as a scope-existence oracle.
        if ($feature === null || ! PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
            abort(404);
        }

        $business = $this->resolveWorkspaceBusiness($workspace, $businessUid);

        try {
            $this->entitlementManager->disableBusinessFeature($business, $feature, $actorUserId);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        } catch (UnauthorizedWorkspaceManagementException) {
            return $this->featureSwitchRefused(self::FEATURE_SWITCH_NOT_ALLOWED, 403);
        } catch (InactiveWorkspaceMutationException) {
            return $this->featureSwitchRefused(self::FEATURE_SWITCH_ACCOUNT_INACTIVE, 409);
        } catch (RuntimeException) {
            return $this->featureSwitchRefused(self::FEATURE_SWITCH_NOT_AVAILABLE, 409);
        }

        return $this->featureSwitchSaved($workspaceUid, false);
    }

    /**
     * RFC-004 Milestone 3 §12/§13: turns a feature back on for one Business
     * (removes its disable preference), regardless of the feature's current
     * effective decision (§13's exact case 1 rule). Same negotiation as
     * disableBusinessFeature().
     */
    public function enableBusinessFeature(string $workspaceUid, string $businessUid, string $featureKey): RedirectResponse|JsonResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveAccessibleWorkspace($workspaceUid, $actorUserId);
        $feature = PlatformFeature::tryFrom($featureKey);

        // Correction 2 — same wrong-scope boundary as disableBusinessFeature().
        if ($feature === null || ! PlatformFeatureRegistry::isBusinessScoped($feature->value)) {
            abort(404);
        }

        $business = $this->resolveWorkspaceBusiness($workspace, $businessUid);

        try {
            $this->entitlementManager->enableBusinessFeature($business, $feature, $actorUserId);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        } catch (UnauthorizedWorkspaceManagementException) {
            return $this->featureSwitchRefused(self::FEATURE_SWITCH_NOT_ALLOWED, 403);
        } catch (InactiveWorkspaceMutationException) {
            return $this->featureSwitchRefused(self::FEATURE_SWITCH_ACCOUNT_INACTIVE, 409);
        }

        return $this->featureSwitchSaved($workspaceUid, true);
    }

    /**
     * The saved state, as the account page's switch expects it; `enabled` is
     * what the manager just stored, so the switch never shows a state the
     * server did not accept.
     */
    private function featureSwitchSaved(string $workspaceUid, bool $enabled): RedirectResponse|JsonResponse
    {
        if (request()->wantsJson()) {
            return response()->json(['status' => 'success', 'enabled' => $enabled]);
        }

        return redirect()
            ->route('customer.workspaces.show', $workspaceUid)
            ->with('flash_success', 'Saved.');
    }

    /**
     * A refusal after the Workspace, Business and feature were all resolved.
     * `customer_message` is the only text the switch shows; anything else a
     * JSON error carries (the global handler's exception message) is not
     * customer copy and is never displayed.
     */
    private function featureSwitchRefused(string $message, int $status): RedirectResponse|JsonResponse
    {
        if (request()->wantsJson()) {
            return response()->json(['status' => 'error', 'customer_message' => $message], $status);
        }

        return redirect()->back()->with('flash_error', $message);
    }

    /**
     * The accounts this actor can open: every Workspace
     * WorkspaceRepository::allForUser() returns that presentationRow() can
     * render (owner, or an active membership that sees the account frame).
     * index() and the chooser back link read the same list.
     *
     * @return Collection<int, array{uid: string, name: string, is_active: bool, role: string}>
     */
    private function accountChoices(int $userId): Collection
    {
        return $this->workspaceRepository->allForUser($userId)
            ->map(fn (Workspace $workspace) => $this->presentationRow($workspace, $userId))
            ->filter()
            ->values();
    }

    /**
     * Same owner-wins-over-membership precedence as presentationRow(), but
     * returns only the role key (no uid/name row shape) since show() needs
     * just the effective role to decide access and directory visibility.
     *
     * With `$accountFrameOnly` (show() only — Slice 1B Correction Round 1)
     * a selected-scope membership resolves to null as well, because the
     * overview is the account frame (see membershipSeesTheAccountFrame()).
     * Every other caller — the mutation actions and their
     * resolveAccessibleWorkspace() — keeps the pre-existing semantics.
     */
    private function effectiveRoleKey(Workspace $workspace, int $userId, bool $accountFrameOnly = false): ?string
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return 'owner';
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null || ! $membership->is_active) {
            return null;
        }

        if ($accountFrameOnly && ! $this->membershipSeesTheAccountFrame($membership)) {
            return null;
        }

        return $membership->role === WorkspaceMembershipRole::Admin ? 'admin' : 'staff';
    }

    /**
     * Customer Experience contract §5.2/§5.4/§6 (Slice 1B Correction 1):
     * the Account/Workspace frame belongs to the Workspace owner, active
     * Admins and Agency-wide staff (`business_access_scope = all`). A member
     * whose access is limited to selected Businesses is a client or
     * Business-scoped staff member and must never observe the account
     * frame — its name, plan, staff or sibling Businesses — so such a
     * membership grants no overview access and is 404 like a stranger
     * (existence-disclosure rule). Business routes are unaffected: RFC-003
     * §14.1 access to the assigned Businesses is evaluated elsewhere.
     */
    private function membershipSeesTheAccountFrame(WorkspaceMembership $membership): bool
    {
        // The rule itself now lives in App\Library\Workspace\AccountFrameAccess,
        // so the context switcher and SwitchAccountAction ask the same question
        // this page does rather than carrying their own copy of it. Behaviour is
        // unchanged: callers here have already rejected an inactive membership,
        // which the shared predicate also refuses.
        return AccountFrameAccess::membershipAllows($membership);
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

        if (! $this->membershipSeesTheAccountFrame($membership)) {
            // A selected-scope (client / Business-scoped) member never sees
            // the account list either — Slice 1B Correction 1, contract §5.4.
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
