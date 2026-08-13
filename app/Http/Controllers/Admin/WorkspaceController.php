<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Workspace\AdminWorkspaceIndexRequest;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;

/**
 * Admin-only, intentionally cross-tenant, READ-ONLY Workspace inspection
 * (RFC-003 Milestone 5 — `docs/automation/RFC-003-M5-CONTRACT.md`). No
 * WorkspaceManager dependency: this controller never mutates, so none of
 * WorkspaceManager's customer-facing authority/locking logic applies here.
 * Platform-administrator access is intentionally independent of Workspace
 * owner/membership/business_access_scope — those are customer-side RFC-003
 * §14.1 concerns and are never consulted by either action below.
 */
class WorkspaceController extends AdminBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
    ) {
    }

    /**
     * Deliberately zero-argument to stay signature-compatible with
     * AdminBaseController::index() — mirrors BusinessController::index()'s
     * identical rationale. The dedicated Form Request is instead resolved
     * through the container, which triggers Laravel's normal authorization/
     * validation lifecycle identically to type-hinting it as a method
     * parameter.
     */
    public function index(): View
    {
        $this->authorize('view workspace');

        $request = app(AdminWorkspaceIndexRequest::class);

        $workspaces = $this->workspaceRepository->paginateForAdmin($request->filters(), $request->perPage());

        return view('admin.workspaces.index', [
            'workspaces' => $workspaces,
            'filters' => $request->filters(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function show(Workspace $workspace): View
    {
        $this->authorize('view workspace');

        $workspace->loadMissing(['owner', 'businesses.customer.user', 'memberships.user', 'memberships.assignedBusinesses']);

        return view('admin.workspaces.show', [
            'workspace' => $workspace,
            'breadcrumbs' => $this->breadcrumbs($workspace),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(?Workspace $workspace = null): array
    {
        $breadcrumbs = [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.workspaces.index'), 'name' => 'Workspaces'],
        ];

        if ($workspace !== null) {
            $breadcrumbs[] = ['name' => $workspace->name];
        }

        return $breadcrumbs;
    }
}
