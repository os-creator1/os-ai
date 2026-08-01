<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
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
