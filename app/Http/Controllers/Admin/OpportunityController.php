<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Http\Requests\Opportunity\AdminOpportunityIndexRequest;
use App\Models\Opportunity;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Contracts\View\View;

/**
 * Admin-only, intentionally cross-tenant Opportunity inspection (RFC-002
 * §44, §51 Milestone 5, Slices 1–2). Read-only index and detail — no run/
 * candidate inspection and no mutation exists yet. Every query goes through
 * a repository contract; nothing here queries the Opportunity model (or any
 * other Eloquent model) directly.
 */
class OpportunityController extends AdminBaseController
{
    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
        private readonly OpportunityTransitionRepository $transitionRepository,
        private readonly OpportunityActionExecutionRepository $actionExecutionRepository,
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

    public function show(int $opportunity): View
    {
        $this->authorize('view opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $targetOpportunity = $this->opportunityRepository->findForAdmin($opportunity);

        if ($targetOpportunity === null) {
            abort(404);
        }

        $transitions = $this->transitionRepository->forOpportunity($targetOpportunity->id);
        $executions = $this->actionExecutionRepository->allForOpportunity($targetOpportunity->id);

        return view('admin.opportunities.show', [
            'opportunity' => $targetOpportunity,
            'recommendedAction' => $this->recommendedActionDiagnostics($targetOpportunity),
            'transitions' => $transitions,
            'executions' => $executions,
            'breadcrumbs' => $this->breadcrumbs($targetOpportunity),
        ]);
    }

    /**
     * Safe, admin-diagnostic view of the persisted recommended_action —
     * never the raw recommended_action array itself (RFC-002 §46).
     * schema_version/hash come from the Opportunity's own native columns;
     * completion_policy/approval_required come from the persisted JSON.
     * Registry-only metadata (mutates_business_data, handler_identifier,
     * verifier_identifier) is deliberately excluded: it is not persisted
     * anywhere on the Opportunity or its execution history, and the
     * registry can change after an Opportunity/execution is recorded —
     * presenting current registry state as if it were historical fact
     * would misrepresent what actually happened.
     *
     * @return array{action_key: ?string, action_schema_version: ?int, recommended_action_hash: ?string, completion_policy: ?string, approval_required: ?bool}
     */
    private function recommendedActionDiagnostics(Opportunity $opportunity): array
    {
        $recommendedAction = $opportunity->recommended_action;
        $actionKey = is_array($recommendedAction) ? ($recommendedAction['action_key'] ?? null) : null;
        $actionKey = is_string($actionKey) ? $actionKey : null;

        return [
            'action_key' => $actionKey,
            'action_schema_version' => $opportunity->action_schema_version,
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'completion_policy' => is_array($recommendedAction) ? ($recommendedAction['completion_policy'] ?? null) : null,
            'approval_required' => is_array($recommendedAction) ? ($recommendedAction['approval_required'] ?? null) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(?Opportunity $opportunity = null): array
    {
        $breadcrumbs = [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.opportunities.index'), 'name' => 'Opportunities'],
        ];

        if ($opportunity !== null) {
            $breadcrumbs[] = ['name' => 'Opportunity #' . $opportunity->id];
        }

        return $breadcrumbs;
    }
}
