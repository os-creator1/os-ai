<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * B5 Business Analytics — the one Business-scoped Analytics surface
 * (contract §1, §2, §12, §13).
 *
 * Every Business action, without exception, resolves the target exactly
 * as UsageBillingController::resolveViewableBusiness() does: Workspace by
 * uid → Business INSIDE that Workspace → WorkspaceManager::
 * userCanAccessBusiness() → abort(404), never 403, on any mismatch. Only
 * after that chain is the range validated, so a foreign identifier can
 * never be probed through a validation answer. Auth::id() is the
 * capability/actor argument only, never a tenant key; there is no
 * primary-Business inference and no LegacyBusinessResolver. Every KPI the
 * presenter reads is constrained by the resolved Business id.
 *
 * Read-only by construction: no action writes anything except ordinary
 * range/pagination state carried in the query string.
 */
class AnalyticsController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessAnalyticsPresenter $presenter,
    ) {
    }

    /**
     * Contract §2.4 — the bare `/analytics` entry: 0 accessible
     * Businesses → empty state; exactly 1 → redirect; more → chooser.
     * Never guesses or infers a primary Business.
     */
    public function entry(): View|RedirectResponse
    {
        $this->authorize('view_reports');

        $accessible = $this->accessibleBusinesses();

        if (count($accessible) === 0) {
            return view('customer.business.analytics.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]);
        }

        return view('customer.business.analytics.entry', ['accessible' => $accessible]);
    }

    public function overview(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('view_reports');
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, (int) Auth::id());

        try {
            $range = $this->resolveRange($request, $business);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('customer.workspaces.businesses.analytics.overview', [$workspaceUid, $businessUid])
                ->withErrors($exception->errors());
        }

        return view('customer.business.analytics.overview', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'range' => $range,
            'analytics' => $this->presenter->buildOverview($business, $range),
            'opportunityEnabled' => (bool) config('opportunity.enabled', false),
        ]);
    }

    public function campaigns(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('view_reports');
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, (int) Auth::id());

        try {
            $range = $this->resolveRange($request, $business);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('customer.workspaces.businesses.analytics.campaigns', [$workspaceUid, $businessUid])
                ->withErrors($exception->errors());
        }

        $page = $this->presenter->buildCampaignsPage($business, $range, (int) $request->query('page', 1));
        $page['paginator']->withPath(route('customer.workspaces.businesses.analytics.campaigns', [$workspaceUid, $businessUid]))
            ->appends($range->queryParameters());

        return view('customer.business.analytics.campaigns', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'range' => $range,
            'paginator' => $page['paginator'],
            'rows' => $page['rows'],
        ]);
    }

    /**
     * Contract §12.1 — bounded JSON for the two charts only. Throttled by
     * the route (`throttle:60,1`). No arbitrary metric, campaign-detail or
     * export surface exists.
     */
    public function series(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->authorize('view_reports');

        try {
            $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, (int) Auth::id());
        } catch (NotFoundHttpException) {
            // The application's global handler rewrites exceptions on JSON
            // requests into a 200 "error" envelope; a foreign or unknown
            // identifier on this endpoint must remain a genuine 404 (§2.2).
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $range = $this->resolveRange($request, $business);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The selected range is invalid.', 'errors' => $exception->errors()], 422);
        }

        return response()->json($this->presenter->buildSeries($business, $range));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Contract §2.2 — reproduced verbatim from
     * UsageBillingController::resolveViewableBusiness().
     */
    private function resolveViewableBusiness(string $workspaceUid, string $businessUid, int $userId): Business
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)
            ->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        return $business;
    }

    /**
     * Shape validation (AnalyticsRangeRequest::ruleSet()) followed by the
     * semantic rules in AnalyticsDateRange, both only AFTER tenancy.
     *
     * @throws ValidationException
     */
    private function resolveRange(Request $request, Business $business): AnalyticsDateRange
    {
        $validated = Validator::make($request->query(), AnalyticsRangeRequest::ruleSet())->validate();

        return AnalyticsDateRange::fromInput($validated, (string) ($business->timezone ?: config('app.timezone', 'UTC')));
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: Business}>
     */
    private function accessibleBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }
}
