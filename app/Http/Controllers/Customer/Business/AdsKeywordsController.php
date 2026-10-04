<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Mutations\GoogleAdsPendingConfirmations;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsKeywordReader;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 — Keywords: the performance table plus the separate,
 * read-only negative-keyword list. Cached data only; pause / resume post to
 * AdsMutationController. google_ads_module only, then `view_google_ads`.
 */
class AdsKeywordsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsKeywordReader $keywords,
        private readonly GoogleAdsCampaignReader $campaigns,
        private readonly GoogleAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'keywords');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.keywords', $data + ['period' => null, 'result' => null]);
        }

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);
        $campaignOptions = $this->campaigns->options($account);
        $campaign = GoogleAdsListInput::campaign($request->query('campaign'), $campaignOptions);
        $status = GoogleAdsListInput::choice($request->query('status'), ['enabled', 'paused']);
        $sort = GoogleAdsListInput::sort($request->query('sort'), GoogleAdsKeywordReader::SORTS);
        $direction = GoogleAdsListInput::direction($request->query('dir'), $sort);

        $result = $this->keywords->page($account, $period, $campaign, $status, $sort, $direction, GoogleAdsListInput::page($request->query('page')));

        return view('customer.business.ads.keywords', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'statusFilter' => $status,
            'campaignFilter' => $campaign,
            'campaignOptions' => $campaignOptions,
            'negatives' => $this->keywords->negatives($account, $campaign),
            'hasQualityScore' => collect($result->items)->contains(fn ($row) => $row->qualityScore !== null),
            'pending' => $this->pending->forKeywords($account, array_map(fn ($row) => $row->uid, $result->items)),
            'returnQuery' => GoogleAdsListInput::returnQuery($request->query()),
        ]);
    }
}
