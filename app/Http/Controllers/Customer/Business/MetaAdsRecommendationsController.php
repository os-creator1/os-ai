<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\MetaAds\MetaAdsDisplay;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFactReader;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationPresenter;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationType;
use App\Library\MetaAds\Reporting\MetaAdsListInput;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Meta Ads Module V1 contract 24 §12 — Recommendations: a READ-ONLY
 * presentation of the deterministic facts (MetaAdsRecommendationFactReader)
 * worded by MetaAdsRecommendationPresenter, each with at most one link to the
 * page that owns it. No dismiss / snooze / apply lifecycle, no stored state,
 * no AI. Cached data only; full Ads module only, then `view_meta_ads`.
 */
class MetaAdsRecommendationsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly MetaAdsRecommendationFactReader $facts,
        private readonly MetaAdsRecommendationPresenter $presenter,
    ) {
    }

    public function listing(Request $request, string $workspaceUid, string $businessUid): View
    {
        $data = $this->resolveMetaAdsModulePage($workspaceUid, $businessUid, 'recommendations');
        $account = $data['account'];

        if ($account === null) {
            return view('customer.business.ads.meta.recommendations', $data + ['period' => null, 'cards' => [], 'resultLabel' => null]);
        }

        $period = MetaAdsPeriod::resolve(MetaAdsListInput::period($request->query('period')), $account);
        $cards = [];

        foreach ($this->facts->facts($account, $period) as $fact) {
            $presented = $this->presenter->present($fact);
            $positive = $fact->type === MetaAdsRecommendationType::StrongPerformer;

            $cards[] = $presented + [
                'type' => $fact->type->value,
                'tone' => $this->tone($fact->type),
                // A strong performer is a positive observation: nothing to act on, so no link.
                'action_url' => $positive ? null : $this->actionUrl($presented['link_target'], $presented['target_uid'], (string) $data['workspaceUid'], (string) $data['businessUid'], $period->key),
            ];
        }

        return view('customer.business.ads.meta.recommendations', $data + [
            'period' => $period,
            'cards' => $cards,
            'resultLabel' => MetaAdsDisplay::resultLabel($account->result_action_type),
        ]);
    }

    /** neutral / amber / green information styling: never red for ordinary optimisation. */
    private function tone(MetaAdsRecommendationType $type): string
    {
        return match ($type) {
            MetaAdsRecommendationType::StrongPerformer => 'success',
            MetaAdsRecommendationType::CostPerResultAboveTarget,
            MetaAdsRecommendationType::PacingOver,
            MetaAdsRecommendationType::HighFrequencyWeakResults,
            MetaAdsRecommendationType::DeliveryIssue => 'warning',
            default => 'neutral',
        };
    }

    /** The presenter's stable link target mapped to the page that owns it; unknown => no link. */
    private function actionUrl(string $target, ?string $targetUid, string $workspaceUid, string $businessUid, string $periodKey): ?string
    {
        $prefix = 'customer.workspaces.businesses.ads.meta.';
        $base = [$workspaceUid, $businessUid];

        return match ($target) {
            'campaign' => $targetUid === null ? null : route($prefix . 'campaigns.show', [...$base, $targetUid, 'period' => $periodKey]),
            'ad_set' => route($prefix . 'ad-sets.index', $base + ['period' => $periodKey]),
            'ad' => route($prefix . 'ads.index', $base + ['period' => $periodKey]),
            'budget' => route($prefix . 'index', $base),
            default => null,
        };
    }
}
