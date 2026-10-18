<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Attribution\LeadAttributionReader;
use App\Library\GoogleAds\Reporting\GoogleAdsCampaignReader;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 contract §11 — Leads & conversions: two clearly
 * separated measurements.
 *
 *   (a) Google conversions: Google's own count, by campaign, in the Ads
 *       account currency (cached daily facts).
 *   (b) Business OS outcomes: the leads this platform recorded (contacts from
 *       a public form, website form or booking) with what we truly know about
 *       where they came from (LeadAttributionReader). It never claims a
 *       Google campaign or keyword, and its CRM money is in the BUSINESS
 *       currency, never summed or compared with Ads-currency amounts.
 *
 * Cached / local data only; no provider call. google_ads_module only, then
 * `view_google_ads`.
 */
class AdsLeadsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsCampaignReader $campaigns,
        private readonly LeadAttributionReader $attribution,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'leads');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.leads', $data + ['period' => null]);
        }

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);

        // created_at is stored in the app (UTC) zone; the period is whole account-local days.
        $storage = (string) config('app.timezone', 'UTC');
        $from = $period->from->startOfDay()->setTimezone($storage);
        $to = $period->to->endOfDay()->setTimezone($storage);

        $page = GoogleAdsListInput::page($request->query('page'));
        $leads = $this->attribution->page($data['business'], $from, $to, $page)
            ->withPath($request->url())
            ->appends(['period' => $period->key]);

        return view('customer.business.ads.leads', $data + [
            'period' => $period,
            'googleCampaigns' => $this->campaigns->list($account, $period),
            'summary' => $this->attribution->summary($data['business'], $from, $to),
            'leads' => $leads,
        ]);
    }
}
