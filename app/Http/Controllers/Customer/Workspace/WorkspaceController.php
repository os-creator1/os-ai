<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Workspace\RenameWorkspaceRequest;
use App\Http\Requests\Customer\Workspace\StoreWorkspaceRequest;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
     * RFC-003 Milestone 3 Slice 3C Business list: every Business in this
     * Workspace for which userCanAccessBusiness() returns true, sorted by
     * the persisted businesses.id ascending for a deterministic list.
     * Never exposes that numeric ID — each row carries only `name`.
     *
     * @return array<int, array{name: string}>
     */
    private function effectiveBusinesses(Workspace $workspace, int $userId): array
    {
        return $this->workspaceRepository->businessesForWorkspace($workspace)
            ->filter(fn (Business $business) => $this->workspaceManager->userCanAccessBusiness($userId, $business))
            ->sortBy('id')
            ->values()
            ->map(fn (Business $business) => ['name' => $business->name])
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
     * Active memberships only, ordered by workspace_memberships.id
     * ascending; the owner is never synthesized as a row. Each row's
     * assigned-Business count is the real
     * workspace_membership_businesses row count for that membership, not
     * assumed from the access-scope label.
     *
     * @return array<int, array{name: string, role: string, scope: string, assigned_business_count: int}>
     */
    private function membershipDirectory(Workspace $workspace): array
    {
        return $this->membershipRepository->activeForWorkspace($workspace)
            ->sortBy('id')
            ->values()
            ->map(fn (WorkspaceMembership $membership) => [
                'name' => trim($membership->user->first_name . ' ' . $membership->user->last_name),
                'role' => $membership->role === WorkspaceMembershipRole::Admin ? 'Admin' : 'Staff',
                'scope' => $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                    ? 'All Businesses'
                    : 'Selected Businesses',
                'assigned_business_count' => $this->membershipBusinessRepository
                    ->assignedBusinessIds($membership)
                    ->count(),
            ])
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
