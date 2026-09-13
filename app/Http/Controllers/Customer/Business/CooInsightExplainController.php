<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Coo\CooInsightTrigger;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Jobs\Coo\GenerateCooInsight;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Coo\Insight\CooInsightExplainLimiter;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Contract §8.2 E-4 (slice AI-3) — "Explain this change" on the Business
 * performance band.
 *
 * Single-shot and stateless: one explanation of the window the customer is
 * looking at, for the Business the CustomerContext resolved (never the URL
 * alone, §15.1). No thread, no history, no reply text in this response — the
 * request only queues GenerateCooInsight on the interactive lane, and the
 * answer appears under "What we notice" once it is cached and valid.
 *
 * Refused, with nothing queued, when: the Business is not the resolved one
 * (404), a client is being viewed as (§15.5, also enforced by the view-as
 * route boundary), the actor may not see results, the plan does not include
 * `ai_coo_basic`, AI is switched off, the window is invalid, or this Business
 * already asked inside the window.
 */
class CooInsightExplainController extends CustomerBaseController
{
    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly CooInsightExplainLimiter $limiter,
    ) {
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $context = $request->attributes->get('customerContext');

        if (! $context instanceof CustomerContext
            || ! $context->isBusinessFrame()
            || $context->selectedBusiness?->uid !== $businessUid
            || $context->frameWorkspace()?->uid !== $workspaceUid) {
            abort(404);
        }

        if ($context->isViewingAsClient()) {
            abort(403);
        }

        $business = Business::query()
            ->whereKey($context->selectedBusiness->id)
            ->where('workspace_id', $context->frameWorkspace()->id)
            ->first();

        if ($business === null || ! Gate::forUser($request->user())->allows('view_reports')) {
            abort(404);
        }

        try {
            $range = Validator::make($request->only(['range', 'start', 'end']), AnalyticsRangeRequest::ruleSet())->validate();
            $window = AnalyticsDateRange::fromInput(
                ($range['range'] ?? null) === null ? ['range' => BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET] : $range,
                (string) ($business->timezone ?: config('app.timezone', 'UTC')),
            );
        } catch (ValidationException) {
            return $this->home([], 'error', "That date range can't be explained. Choose a supported range and try again.");
        }

        $parameters = $window->queryParameters();

        if (! (bool) config('services.openai.active') || ! $this->shell->currentMenuEntitlements($context)->allows('ai_coo_basic')) {
            return $this->home($parameters, 'info', "AI explanations aren't available for this business.");
        }

        if (! $this->limiter->claim((int) $business->id)) {
            return $this->home($parameters, 'info', 'An explanation was already requested for this business in the last '
                . max(1, (int) config('coo.insight.explain_window_hours', 24)) . ' hours.');
        }

        GenerateCooInsight::dispatch((int) $business->id, CooInsightTrigger::ExplainThisChange->value, $parameters, (int) $request->user()->id);

        return $this->home($parameters, 'info', "We're preparing an explanation of this change. It will appear under What we notice when it's ready.");
    }

    /** @param array<string, string> $parameters */
    private function home(array $parameters, string $status, string $message): RedirectResponse
    {
        return redirect()->route('user.home', $parameters)->with(['status' => $status, 'message' => $message]);
    }
}
