<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\MetaAdsDisplay;
use App\Library\MetaAds\Reporting\MetaAdsAdSetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsListInput;
use App\Library\MetaAds\Reporting\MetaAdsPendingConfirmations;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Meta Ads Module V1 — the Ad sets list. Cached data only (no Meta call on
 * GET); full Ads module only, then `view_meta_ads`. The `campaign` filter is
 * kept only when it is one of the account's own campaign uids.
 */
class MetaAdsAdSetsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly MetaAdsAdSetReader $adSets,
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'ad-sets');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.ad-sets', $data + ['period' => null, 'result' => null]);
        }

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);
        $sort = MetaAdsListInput::sort($request->query('sort'), MetaAdsAdSetReader::SORTS);
        $direction = MetaAdsListInput::direction($request->query('dir'), $sort);
        $status = MetaAdsListInput::status($request->query('status'));
        $campaignOptions = $this->campaigns->options($account);
        $campaign = MetaAdsListInput::entity($request->query('campaign'), $campaignOptions);
        $result = $this->adSets->page($account, $period, $status, $campaign, $sort, $direction, MetaAdsListInput::page($request->query('page')));

        return view('customer.business.ads.meta.ad-sets', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'statusFilter' => $status,
            'campaignFilter' => $campaign,
            'campaignOptions' => $campaignOptions,
            'resultLabel' => MetaAdsDisplay::resultLabel($account->result_action_type),
            'pending' => $this->pending->forAdSets($account, array_map(fn ($row) => $row->uid, $result->items)),
            'returnQuery' => MetaAdsListInput::returnQuery($request->query()),
        ]);
    }
}
