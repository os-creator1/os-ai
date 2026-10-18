<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\Recommendations\MetaAdsAttentionItems;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\MetaAds\Reporting\MetaAdsTrendSeries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Meta Ads Module V1 (contract 24 §5/§9) — the Meta Overview and its chart
 * series. GET only and CACHED DATA ONLY: every figure comes from the
 * normalised tables the sync fills, so changing the period never calls Meta.
 *
 * Tenancy (ResolvesMetaAdsBusinessTenancy): Workspace -> Business -> active
 * Business -> ads_basic_visibility OR the full Ads module -> `view_meta_ads`.
 * Every tenancy / entitlement failure is a 404.
 */
class MetaAdsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly MetaAdsOverviewReader $overviewReader,
        private readonly MetaAdsTrendSeries $trend,
        private readonly MetaAdsAttentionItems $attention,
    ) {
    }

    public function overview(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('view_meta_ads');

        $data = $this->metaAdsViewData($workspace, $business, 'overview');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.overview', $data + ['period' => null, 'overview' => null]);
        }

        $period = MetaAdsPeriod::resolve(is_string($request->query('period')) ? $request->query('period') : null, $account);
        $overview = $this->overviewReader->read($account, $period);

        return view('customer.business.ads.meta.overview', $data + [
            // Acquisition Purpose + Ads Decisioning V1: the deterministic "What should you do now?" block.
            'decisionView' => app(\App\Library\Ads\Decisions\AdsDecisionPresenter::class)->present(
                app(\App\Library\Ads\Decisions\AdsDecisionPanelReader::class)->forMeta($business, $account, $period),
                'meta',
                $business,
                (bool) $data['metaHasModule'],
            ),
            'period' => $period,
            'overview' => $overview,
            'trend' => $this->trend->forPeriod($account, $period),
            'hasCampaigns' => $account->campaigns()->exists(),
            'attention' => $this->attention->top($account, (string) $workspace->uid, (string) $business->uid, (bool) $data['metaHasModule'], 5, $period),
            'seriesUrl' => route('customer.workspaces.businesses.ads.meta.series', [$workspaceUid, $businessUid, 'period' => $period->key]),
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
            [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);
        } catch (HttpException) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (Gate::denies('view_meta_ads')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $account = $this->resolveMetaAdsAccount($business);

        if ($account === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $period = MetaAdsPeriod::resolve(is_string($request->query('period')) ? $request->query('period') : null, $account);

        return response()->json($this->trend->forPeriod($account, $period));
    }
}
