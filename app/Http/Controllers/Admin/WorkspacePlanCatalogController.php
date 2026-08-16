<?php

namespace App\Http\Controllers\Admin;

use App\Library\Entitlement\EntitlementManager;
use Illuminate\Contracts\View\View;

/**
 * Admin-only, read-only Workspace plan catalog inspection (RFC-004
 * Milestone 3). Never reads any entitlement repository directly — every
 * value comes from EntitlementManager::listPlanCatalogSummaries() (§8.2).
 */
class WorkspacePlanCatalogController extends AdminBaseController
{
    public function __construct(
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    public function index(): View
    {
        $this->authorize('view workspace plans');

        return view('admin.workspace-plan-catalog.index', [
            'catalogSummaries' => $this->entitlementManager->listPlanCatalogSummaries(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Workspace Plan Catalog'],
        ];
    }
}
