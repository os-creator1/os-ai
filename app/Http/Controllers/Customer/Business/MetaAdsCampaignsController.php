<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\MetaAdsDisplay;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsListInput;
use App\Library\MetaAds\Reporting\MetaAdsPendingConfirmations;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Meta Ads Module V1 — Campaigns (list) and the campaign detail page.
 *
 * READ-ONLY and CACHED DATA ONLY: nothing here calls Meta. The pause / resume
 * actions on these pages post to MetaAdsMutationController. Entitlement is the
 * full Ads module only (Core => 404), then `view_meta_ads`. A campaign is
 * addressed by its uid and must belong to the Business's selected account: an
 * unknown, foreign or malformed uid is a 404.
 */
class MetaAdsCampaignsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'campaigns');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.campaigns', $data + ['period' => null, 'result' => null]);
        }

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);
        $sort = MetaAdsListInput::sort($request->query('sort'), MetaAdsCampaignReader::SORTS);
        $direction = MetaAdsListInput::direction($request->query('dir'), $sort);
        $status = MetaAdsListInput::status($request->query('status'));
        $result = $this->campaigns->page($account, $period, $status, $sort, $direction, MetaAdsListInput::page($request->query('page')));

        return view('customer.business.ads.meta.campaigns', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'statusFilter' => $status,
            'resultLabel' => MetaAdsDisplay::resultLabel($account->result_action_type),
            'pending' => $this->pending->forCampaigns($account, array_map(fn ($row) => $row->uid, $result->items)),
            'returnQuery' => MetaAdsListInput::returnQuery($request->query()),
        ]);
    }

    public function detail(Request $request, string $workspaceUid, string $businessUid, string $campaignUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'campaigns');
        $account = $data['account'];

        // No selected account => nothing in it can exist, so a campaign uid is unknown.
        abort_if($account === null, 404);

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);
        $detail = $this->campaigns->detail($account, $campaignUid, $period);

        abort_if($detail === null, 404);

        return view('customer.business.ads.meta.campaign', $data + [
            'period' => $period,
            'detail' => $detail,
            'resultLabel' => MetaAdsDisplay::resultLabel($account->result_action_type),
            'pending' => $this->pending->forCampaigns($account, [$detail->campaign->uid]),
            'returnQuery' => MetaAdsListInput::returnQuery($request->query()),
        ]);
    }
}
