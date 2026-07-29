<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Opportunity\AdminOpportunityRunIndexRequest;
use App\Models\Business;
use App\Models\OpportunityRun;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Contracts\View\View;

/**
 * Admin-only, intentionally cross-tenant producer-run and staged-candidate
 * inspection (RFC-002 §44, §51 Milestone 5, Slice 3). Read-only — no
 * mutation, no OpportunityManager call, no job dispatch. Every query goes
 * through a repository contract; nothing here queries the Business,
 * OpportunityRun, or OpportunityRunCandidate models directly. Failed and
 * abandoned runs and their candidates are shown identically to any other
 * run — this is exactly what the admin diagnostic surface is for.
 */
class OpportunityRunController extends AdminBaseController
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly OpportunityRunRepository $runRepository,
        private readonly OpportunityRunCandidateRepository $candidateRepository,
    ) {
    }

    public function index(): View
    {
        $this->authorize('view opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $request = app(AdminOpportunityRunIndexRequest::class);

        $business = $this->businessRepository->findById($request->businessId());

        if ($business === null) {
            abort(404);
        }

        $runs = $this->runRepository->paginateForBusiness($business->id, $request->perPage());

        return view('admin.opportunities.runs.index', [
            'business' => $business,
            'runs' => $runs,
            'perPage' => $request->perPage(),
            'breadcrumbs' => $this->breadcrumbs($business),
        ]);
    }

    public function show(int $run): View
    {
        $this->authorize('view opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $targetRun = $this->runRepository->findForAdmin($run);

        if ($targetRun === null) {
            abort(404);
        }

        $candidates = $this->candidateRepository->orderedForRun($targetRun->id);

        return view('admin.opportunities.runs.show', [
            'run' => $targetRun,
            'candidates' => $candidates,
            'breadcrumbs' => $this->breadcrumbs($targetRun->business, $targetRun),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(?Business $business, ?OpportunityRun $run = null): array
    {
        $breadcrumbs = [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.opportunities.index'), 'name' => 'Opportunities'],
        ];

        if ($business !== null) {
            $breadcrumbs[] = [
                'link' => route('admin.opportunities.runs.index', ['business_id' => $business->id]),
                'name' => 'Runs — ' . $business->name,
            ];
        }

        if ($run !== null) {
            $breadcrumbs[] = ['name' => 'Run #' . $run->id];
        }

        return $breadcrumbs;
    }
}
