<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Http\Requests\Opportunity\AdminOpportunityIndexRequest;
use App\Http\Requests\Opportunity\SnoozeOpportunityRequest;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\InvalidSnoozeUntilException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Opportunity;
use App\Models\User;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Admin-only, intentionally cross-tenant Opportunity inspection and manual
 * state mutation (RFC-002 §44, §51 Milestone 5, Slices 1–4). Every query
 * goes through a repository contract; every mutation goes through
 * OpportunityManager's admin-specific methods — nothing here queries or
 * mutates the Opportunity model (or any other Eloquent model) directly. No
 * approval, configuration, execution, retry, or attestation control exists
 * here — those remain customer-only.
 */
class OpportunityController extends AdminBaseController
{
    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
        private readonly OpportunityTransitionRepository $transitionRepository,
        private readonly OpportunityActionExecutionRepository $actionExecutionRepository,
        private readonly OpportunityManager $opportunityManager,
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
     * Mirrors the customer controller's own snooze() exactly: a fixed,
     * server-computed instant derived only from the validated duration key
     * — never a client-supplied timestamp, never parsed in Blade, no
     * timezone guessing (always relative to the server's own now()).
     * SnoozeOpportunityRequest is reused unmodified — its authorize()/
     * rules() carry no customer-specific assumption.
     */
    public function snooze(SnoozeOpportunityRequest $request, int $opportunity): RedirectResponse
    {
        $this->authorize('edit opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $targetOpportunity = $this->opportunityRepository->findForAdmin($opportunity);

        if ($targetOpportunity === null) {
            abort(404);
        }

        $until = match ($request->validated('duration')) {
            '1_day' => now()->addDay(),
            '3_days' => now()->addDays(3),
            '1_week' => now()->addWeek(),
        };

        try {
            $this->opportunityManager->snoozeAsAdmin($targetOpportunity, $this->admin(), $until);
        } catch (InvalidOpportunityStateException|InvalidSnoozeUntilException) {
            return $this->safeErrorRedirect($targetOpportunity->id, "This action isn't available for this opportunity right now.");
        }

        return redirect()->route('admin.opportunities.show', $targetOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity snoozed.',
        ]);
    }

    public function dismiss(int $opportunity): RedirectResponse
    {
        $this->authorize('edit opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $targetOpportunity = $this->opportunityRepository->findForAdmin($opportunity);

        if ($targetOpportunity === null) {
            abort(404);
        }

        try {
            $this->opportunityManager->dismissAsAdmin($targetOpportunity, $this->admin());
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect($targetOpportunity->id, "This action isn't available for this opportunity right now.");
        }

        return redirect()->route('admin.opportunities.show', $targetOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity dismissed.',
        ]);
    }

    /**
     * The reopen reason is always the fixed 'admin_reopened' literal — never
     * a request-supplied value (RFC-002 §44). OpportunityManager's
     * reopenAsAdmin() still accepts reasonCode as an explicit parameter,
     * matching the RFC's exact signature, but this controller is the one
     * place that must never forward request input into it.
     */
    public function reopen(int $opportunity): RedirectResponse
    {
        $this->authorize('edit opportunities');

        abort_unless(config('opportunity.enabled', false), 404);

        $targetOpportunity = $this->opportunityRepository->findForAdmin($opportunity);

        if ($targetOpportunity === null) {
            abort(404);
        }

        try {
            $this->opportunityManager->reopenAsAdmin($targetOpportunity, $this->admin(), 'admin_reopened');
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect($targetOpportunity->id, "This action isn't available for this opportunity right now.");
        }

        return redirect()->route('admin.opportunities.show', $targetOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity reopened.',
        ]);
    }

    /**
     * The fixed-message redirect every domain exception maps to (RFC-002
     * §47) — never the raw exception message, which always interpolates an
     * internal Opportunity ID.
     */
    private function safeErrorRedirect(int $opportunityId, string $message): RedirectResponse
    {
        return redirect()->route('admin.opportunities.show', $opportunityId)->withErrors(['opportunity' => $message]);
    }

    private function admin(): User
    {
        return Auth::user();
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
