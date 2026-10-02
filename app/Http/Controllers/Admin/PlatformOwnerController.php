<?php

namespace App\Http\Controllers\Admin;

use App\Library\PlatformOwner\PlatformOwnerOverviewReader;
use App\Library\PlatformOwner\WorkspaceSupportReader;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Platform Owner / Admin V1 — the overview and the audit trail. Both are
 * READ-ONLY and sit inside the EnsureUserIsAdministrator route group (the
 * single Platform Owner boundary, PlatformOwnerAuthority) on top of the
 * admin group's 'can:access backend' gate; each action additionally checks
 * the existing 'view workspace' permission, exactly like the Workspace
 * inspection pages it links to.
 *
 * Deliberately thin: every number and row comes from a reader in
 * App\Library\PlatformOwner.
 */
class PlatformOwnerController extends AdminBaseController
{
    private const AUDIT_PER_PAGE = 50;

    public function __construct(
        private readonly PlatformOwnerOverviewReader $overview,
        private readonly WorkspaceSupportReader $support,
        private readonly WorkspaceEntitlementTransitionRepository $transitions,
    ) {
    }

    public function overview(): View
    {
        $this->authorize('view workspace');

        return view('admin.platform-owner.overview', [
            'overview' => $this->overview->overview(),
            'breadcrumbs' => $this->breadcrumbs('Overview'),
        ]);
    }

    public function audit(Request $request): View
    {
        $this->authorize('view workspace');

        $rows = $this->transitions->paginateAdminActions(self::AUDIT_PER_PAGE);

        return view('admin.platform-owner.audit', [
            'rows' => $rows,
            'actors' => $this->support->actorLabels($rows->items()),
            'breadcrumbs' => $this->breadcrumbs('Audit'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(string $trailing): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Platform Owner — ' . $trailing],
        ];
    }
}
