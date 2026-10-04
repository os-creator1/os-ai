<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\Attribution\MetaAdsLeadAttributionReader;
use App\Library\MetaAds\Reporting\MetaAdsListInput;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Meta Ads Module V1 (contract 24 §10) — Leads: two clearly separated
 * measurements.
 *
 *   (a) Meta-reported results: Meta's own count of the owner-chosen result
 *       type for the period, in the Ads account currency (cached daily facts).
 *   (b) Business OS outcomes: leads this platform already recorded whose first
 *       touch carries a Meta source TAG, with the tag text, CRM stage, booked
 *       and CRM opportunity value in the BUSINESS currency.
 *
 * Nothing here is captured by Meta Ads V1 (no Pixel, no Conversions API, no
 * click ids): a tag is not proof of a paid click and never names a Meta
 * campaign, ad set or ad. Cached / local data only. Full module only (Core
 * 404), then `view_meta_ads`.
 */
class MetaAdsLeadsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly MetaAdsOverviewReader $overviewReader,
        private readonly MetaAdsLeadAttributionReader $attribution,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'leads');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.leads', $data + ['period' => null]);
        }

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);

        // created_at is stored in the app (UTC) zone; the period is whole account-local days.
        $storage = (string) config('app.timezone', 'UTC');
        $from = $period->from->startOfDay()->setTimezone($storage);
        $to = $period->to->endOfDay()->setTimezone($storage);

        $leads = $this->attribution->page($data['business'], $from, $to, MetaAdsListInput::page($request->query('page')))
            ->withPath($request->url())
            ->appends(['period' => $period->key]);

        return view('customer.business.ads.meta.leads', $data + [
            'period' => $period,
            'overview' => $this->overviewReader->read($account, $period),
            'summary' => $this->attribution->summary($data['business'], $from, $to),
            'leads' => $leads,
        ]);
    }
}
