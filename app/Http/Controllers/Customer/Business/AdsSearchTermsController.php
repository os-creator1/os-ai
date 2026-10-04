<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Mutations\GoogleAdsPendingConfirmations;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermClass;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 — Search terms: the page that shows what is wasting
 * money. Cached data only. Add negative / Ignore post to AdsMutationController
 * (Add negative through a server-rendered confirmation step). google_ads_module
 * only, then `view_google_ads`.
 */
class AdsSearchTermsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsSearchTermReader $searchTerms,
        private readonly GoogleAdsCampaignReader $campaigns,
        private readonly GoogleAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'search-terms');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.search-terms', $data + ['period' => null, 'result' => null]);
        }

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);
        $campaignOptions = $this->campaigns->options($account);
        $campaign = GoogleAdsListInput::campaign($request->query('campaign'), $campaignOptions);
        $class = GoogleAdsListInput::choice($request->query('class'), array_map(fn (GoogleAdsSearchTermClass $c) => $c->value, GoogleAdsSearchTermClass::cases()));
        $sort = GoogleAdsListInput::sort($request->query('sort'), GoogleAdsSearchTermReader::SORTS);
        $direction = GoogleAdsListInput::direction($request->query('dir'), $sort);

        $result = $this->searchTerms->page($account, $period, $class, $campaign, $sort, $direction, GoogleAdsListInput::page($request->query('page')));

        // One cheap count per class (a few aggregate reads over cached rows) for the filter tabs.
        $counts = ['all' => $this->searchTerms->page($account, $period, null, $campaign, 'spend', 'desc', 1, 1)->total];

        foreach (GoogleAdsSearchTermClass::cases() as $case) {
            $counts[$case->value] = $this->searchTerms->page($account, $period, $case->value, $campaign, 'spend', 'desc', 1, 1)->total;
        }

        $waste = $this->searchTerms->wasteSummary($account, $period, $campaign);

        return view('customer.business.ads.search-terms', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'classFilter' => $class,
            'campaignFilter' => $campaign,
            'campaignOptions' => $campaignOptions,
            'counts' => $counts,
            'waste' => $waste->hasData && ($waste->termCount ?? 0) > 0 ? $waste : null,
            'pendingNegatives' => $this->pending->negativeKeys($account),
            'returnQuery' => GoogleAdsListInput::returnQuery($request->query()),
        ]);
    }
}
