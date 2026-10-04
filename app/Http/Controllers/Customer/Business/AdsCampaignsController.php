<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Mutations\GoogleAdsPendingConfirmations;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 — Campaigns (list) and the campaign detail page.
 *
 * READ-ONLY and CACHED DATA ONLY: nothing here calls Google. The pause /
 * resume actions on these pages post to AdsMutationController. Entitlement is
 * google_ads_module only (Core => 404), then `view_google_ads`. A campaign is
 * addressed by its uid and must belong to the Business's selected account:
 * an unknown or foreign uid is a 404.
 */
class AdsCampaignsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsCampaignReader $campaigns,
        private readonly GoogleAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'campaigns');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.campaigns', $data + ['period' => null, 'result' => null]);
        }

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);
        $sort = GoogleAdsListInput::sort($request->query('sort'), GoogleAdsCampaignReader::SORTS);
        $direction = GoogleAdsListInput::direction($request->query('dir'), $sort);
        $status = GoogleAdsListInput::choice($request->query('status'), ['enabled', 'paused']);
        $result = $this->campaigns->page($account, $period, $status, $sort, $direction, GoogleAdsListInput::page($request->query('page')));

        return view('customer.business.ads.campaigns', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'statusFilter' => $status,
            'pending' => $this->pending->forCampaigns($account, array_map(fn ($row) => $row->uid, $result->items)),
            'returnQuery' => GoogleAdsListInput::returnQuery($request->query()),
        ]);
    }

    public function detail(Request $request, string $workspaceUid, string $businessUid, string $campaignUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'campaigns');
        $account = $data['account'];

        // No selected account => nothing in it can exist, so a campaign uid is unknown.
        abort_if($account === null, 404);

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);
        $detail = $this->campaigns->detail($account, $campaignUid, $period);

        abort_if($detail === null, 404);

        return view('customer.business.ads.campaign', $data + [
            'period' => $period,
            'detail' => $detail,
            'pending' => $this->pending->forCampaigns($account, [$detail->campaign->uid]),
            'returnQuery' => GoogleAdsListInput::returnQuery($request->query()),
        ]);
    }
}
