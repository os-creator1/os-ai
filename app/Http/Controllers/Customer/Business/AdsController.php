<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\GoogleAds\Reporting\GoogleAdsBudgetReader;
use App\Library\GoogleAds\Reporting\GoogleAdsOverviewReader;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Library\GoogleAds\Reporting\GoogleAdsTrendSeries;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Google Ads Module V1 — the read side of the module shell: the bare entry,
 * the Overview, its chart-series endpoint and the Budget page.
 *
 * READ-ONLY, and CACHED DATA ONLY. No action here makes a provider call:
 * every figure comes from the normalised tables the daily sync fills
 * (contract 23 §5), so changing the period never calls Google.
 *
 * Tenancy (ResolvesAdsBusinessTenancy): Workspace -> Business -> active
 * Business -> entitlement -> `view_google_ads`. Overview needs
 * ads_basic_visibility OR google_ads_module; Budget needs google_ads_module
 * (a Core Business gets 404). Every failure of the tenancy or entitlement
 * steps is 404; a missing capability is the application-wide authorization
 * failure, exactly as in SEO.
 */
class AdsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsOverviewReader $overviewReader,
        private readonly GoogleAdsTrendSeries $trend,
        private readonly GoogleAdsBudgetReader $budgetReader,
        private readonly GoogleAdsSearchTermReader $searchTerms,
    ) {
    }

    /**
     * The bare /ads entry. NEVER guesses a Business: zero accessible shows an
     * empty state, exactly one redirects through, several show a chooser.
     * "Accessible" includes entitlement.
     *
     * ORDER IS LOAD-BEARING: the implementation-availability floor comes
     * FIRST, so while neither Ads feature is Available the whole surface is
     * 404 for every caller (no 200 empty state, no 401 that would reveal the
     * surface exists).
     */
    public function entry(): View|RedirectResponse
    {
        abort_unless($this->adsIsImplementedAndAvailable(), 404);

        // Either provider's read capability opens the bare entry (Meta Ads V1).
        if (Gate::denies('view_google_ads')) {
            $this->authorize('view_meta_ads');
        }

        $accessible = $this->entitledBusinesses();

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            // Meta Ads V1: the single-business redirect lands on the cross-channel Overview.
            return redirect()->route(
                Route::has('customer.workspaces.businesses.ads.overview')
                    ? 'customer.workspaces.businesses.ads.overview'
                    : 'customer.workspaces.businesses.ads.index',
                [$workspace->uid, $business->uid],
            );
        }

        return view('customer.business.ads.entry', ['accessible' => $accessible]);
    }

    public function overview(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('view_google_ads');

        $data = $this->adsViewData($workspace, $business, 'overview');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.overview', $data + ['period' => null, 'overview' => null]);
        }

        $period = GoogleAdsPeriod::resolve(is_string($request->query('period')) ? $request->query('period') : null, $account);
        $overview = $this->overviewReader->read($account, $period);

        // The "Money wasted?" teaser links to the Search terms page, so it is
        // built only when that page exists and the Business may open it.
        $waste = null;

        if ($data['adsHasModule'] && Route::has('customer.workspaces.businesses.ads.search-terms.index')) {
            $waste = $this->searchTerms->wasteSummary($account, $period);
        }

        return view('customer.business.ads.overview', $data + [
            // Acquisition Purpose + Ads Decisioning V1: the deterministic "What should you do now?" block.
            'decisionView' => app(\App\Library\Ads\Decisions\AdsDecisionPresenter::class)->present(
                app(\App\Library\Ads\Decisions\AdsDecisionPanelReader::class)->forGoogle($business, $account, $period),
                'google',
                $business,
                (bool) $data['adsHasModule'],
            ),
            'period' => $period,
            'overview' => $overview,
            'trend' => $this->trend->forPeriod($account, $period),
            'hasCampaigns' => $account->campaigns()->exists(),
            'waste' => $waste,
            'seriesUrl' => route('customer.workspaces.businesses.ads.series', [$workspaceUid, $businessUid, 'period' => $period->key]),
        ]);
    }

    /**
     * The chart data for the Overview. Cached figures only. The global
     * exception handler rewrites exceptions on a JSON request into a 200
     * envelope, so every refusal here is returned as a genuine 404.
     */
    public function series(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        try {
            [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);
        } catch (HttpException) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (Gate::denies('view_google_ads')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $account = $this->resolveAdsAccount($business);

        if ($account === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $period = GoogleAdsPeriod::resolve(is_string($request->query('period')) ? $request->query('period') : null, $account);

        return response()->json($this->trend->forPeriod($account, $period));
    }

    public function budget(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_google_ads');

        $data = $this->adsViewData($workspace, $business, 'budget');

        return view('customer.business.ads.budget', $data + [
            'budgetOverview' => $data['account'] === null ? null : $this->budgetReader->read($data['account']),
        ]);
    }

    /**
     * The implementation-availability floor for the bare entry. Its own
     * method (like SEO's) so a test can reach the post-floor selector logic.
     */
    protected function adsIsImplementedAndAvailable(): bool
    {
        return \App\Library\Ads\AdsFeatureAccess::isImplementedAndAvailable();
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: \App\Models\Business}>
     */
    private function entitledBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            if (! $workspace->is_active) {
                continue;
            }

            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if (! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    continue;
                }

                if ($business->status !== BusinessStatus::Active) {
                    continue;
                }

                if ($this->adsEntitlementAllows($workspace, $business, $userId)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }
}
