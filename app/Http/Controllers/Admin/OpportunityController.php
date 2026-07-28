<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Http\Requests\Opportunity\AdminOpportunityIndexRequest;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Contracts\View\View;

/**
 * Admin-only, intentionally cross-tenant Opportunity inspection (RFC-002
 * §44, §51 Milestone 5, Slice 1). Read-only index in this slice — no detail
 * page, no run/candidate inspection, and no mutation exists yet. Every
 * query goes through OpportunityRepository; nothing here queries the
 * Opportunity model directly.
 */
class OpportunityController extends AdminBaseController
{
    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
    ) {
    }

    /**
     * Deliberately zero-argument to stay signature-compatible with
     * AdminBaseController::index() — mirrors BusinessController::index()'s
     * own precedent exactly. The dedicated Form Request is resolved through
     * the container so it still goes through Laravel's normal
     * authorization/validation lifecycle.
     */
    public function index(): View
    {
        $this->authorize('view opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $request = app(AdminOpportunityIndexRequest::class);

        $opportunities = $this->opportunityRepository->paginateForAdmin($request->filters());

        return view('admin.opportunities.index', [
            'opportunities' => $opportunities,
            'filters' => $request->filters(),
            'statuses' => OpportunityStatus::cases(),
            'freshnesses' => OpportunityFreshness::cases(),
            'workerKeys' => OpportunityWorkerKey::cases(),
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
            ['name' => 'Opportunities'],
        ];
    }
}
