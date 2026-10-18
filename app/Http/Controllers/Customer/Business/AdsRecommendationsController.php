<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationPresenter;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationType;
use App\Library\GoogleAds\Reporting\GoogleAdsListInput;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 contract §12 — Recommendations: a READ-ONLY
 * presentation of the deterministic facts (GoogleAdsRecommendationFactReader)
 * worded by GoogleAdsRecommendationPresenter, each with a link to the page
 * that owns the action.
 *
 * Deliberately NO dismiss / snooze / apply lifecycle and no stored state: the
 * RFC-002 Opportunity Engine owns recommendation lifecycle (contract D7 / §12).
 * Cached data only; no AI. google_ads_module only, then `view_google_ads`.
 */
class AdsRecommendationsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly GoogleAdsRecommendationFactReader $facts,
        private readonly GoogleAdsRecommendationPresenter $presenter,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveAdsModulePage($workspaceUid, $businessUid, 'recommendations');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.recommendations', $data + ['period' => null, 'cards' => []]);
        }

        $period = GoogleAdsPeriod::resolve(GoogleAdsListInput::period($request->query('period')), $account);
        $cards = [];

        foreach ($this->facts->facts($account, $period) as $fact) {
            $presented = $this->presenter->present($fact);

            $cards[] = $presented + [
                'type' => $fact->type->value,
                'tone' => $this->tone($fact->type),
                'action_url' => $this->actionUrl($fact->suggestedAction, (string) $data['workspaceUid'], (string) $data['businessUid'], $period->key),
                // Pacing facts are always about the current month, whatever period is selected.
                'basis_window' => in_array($fact->type, [GoogleAdsRecommendationType::PacingOver, GoogleAdsRecommendationType::PacingUnder], true)
                    ? 'this month'
                    : null,
            ];
        }

        return view('customer.business.ads.recommendations', $data + ['period' => $period, 'cards' => $cards]);
    }

    /** neutral / amber / green information styling: never red for ordinary optimisation. */
    private function tone(GoogleAdsRecommendationType $type): string
    {
        return match ($type) {
            GoogleAdsRecommendationType::StrongCampaign => 'success',
            GoogleAdsRecommendationType::WastedSearchTerms,
            GoogleAdsRecommendationType::CplAboveTarget,
            GoogleAdsRecommendationType::PacingOver => 'warning',
            default => 'neutral',
        };
    }

    /**
     * Each fact's suggested action is a descriptor of an existing flow; this
     * maps it to the page that owns it. Anything unrecognised has no link.
     *
     * @param  array{key: string, target_uid: ?string}  $action
     */
    private function actionUrl(array $action, string $workspaceUid, string $businessUid, string $periodKey): ?string
    {
        $prefix = 'customer.workspaces.businesses.ads.';
        $base = [$workspaceUid, $businessUid];

        return match ($action['key']) {
            'review_search_terms' => route($prefix . 'search-terms.index', $base + ['class' => 'potential_waste', 'period' => $periodKey]),
            'review_campaign' => $action['target_uid'] === null ? null : route($prefix . 'campaigns.show', [...$base, $action['target_uid'], 'period' => $periodKey]),
            'review_budget' => route($prefix . 'budget', $base),
            default => null,
        };
    }
}
