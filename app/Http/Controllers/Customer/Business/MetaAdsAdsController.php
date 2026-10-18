<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\MetaAdsDisplay;
use App\Library\MetaAds\Reporting\MetaAdsAdReader;
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
 * Meta Ads Module V1 — the Ads list with creative previews. Cached data only
 * (no Meta call on GET); full Ads module only, then `view_meta_ads`. The
 * `campaign` / `ad_set` filters are kept only when they are the account's own
 * uids. Creative thumbnails were validated by MetaAdsAdReader; title / body
 * are raw customer text and the view escapes them.
 */
class MetaAdsAdsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly MetaAdsAdReader $ads,
        private readonly MetaAdsAdSetReader $adSets,
        private readonly MetaAdsCampaignReader $campaigns,
        private readonly MetaAdsPendingConfirmations $pending,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'ads');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.ads', $data + ['period' => null, 'result' => null]);
        }

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);
        $sort = MetaAdsListInput::sort($request->query('sort'), MetaAdsAdReader::SORTS);
        $direction = MetaAdsListInput::direction($request->query('dir'), $sort);
        $status = MetaAdsListInput::status($request->query('status'));
        $campaignOptions = $this->campaigns->options($account);
        $adSetOptions = $this->adSets->options($account);
        $campaign = MetaAdsListInput::entity($request->query('campaign'), $campaignOptions);
        $adSet = MetaAdsListInput::entity($request->query('ad_set'), $adSetOptions);
        $result = $this->ads->page($account, $period, $status, $campaign, $adSet, $sort, $direction, MetaAdsListInput::page($request->query('page')));

        return view('customer.business.ads.meta.ads', $data + [
            'period' => $period,
            'result' => $result,
            'sort' => $sort,
            'direction' => $direction,
            'statusFilter' => $status,
            'campaignFilter' => $campaign,
            'adSetFilter' => $adSet,
            'campaignOptions' => $campaignOptions,
            'adSetOptions' => $adSetOptions,
            'resultLabel' => MetaAdsDisplay::resultLabel($account->result_action_type),
            'pending' => $this->pending->forAds($account, array_map(fn ($row) => $row->uid, $result->items)),
            'returnQuery' => MetaAdsListInput::returnQuery($request->query()),
        ]);
    }
}
