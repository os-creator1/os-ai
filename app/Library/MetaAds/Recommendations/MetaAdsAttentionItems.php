<?php

namespace App\Library\MetaAds\Recommendations;

use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Facades\Route;

/**
 * Meta Ads Module V1 contract 24 §9 / §12 — "What needs attention": the top
 * deterministic facts (MetaAdsRecommendationFactReader, already ranked by
 * spend) rendered through MetaAdsRecommendationPresenter and mapped to the
 * page that owns each one. Read-only, cached data, no provider call, and a
 * constant number of queries per call (the fact reader's own bounded set).
 *
 * A link is offered only when the page exists AND the viewer may open it
 * (campaign / ad set / ad pages need the full Ads module; budget facts link
 * to Settings, where the Meta targets live). Titles and evidence lines carry
 * customer data (names): the view must escape them. No provider id is ever
 * present.
 */
final class MetaAdsAttentionItems
{
    private const PREFIX = 'customer.workspaces.businesses.ads.meta.';

    public function __construct(
        private readonly MetaAdsRecommendationFactReader $facts,
        private readonly MetaAdsRecommendationPresenter $presenter,
    ) {
    }

    /**
     * Total number of facts for the account/period (the "issue count").
     */
    public function count(MetaAdsAccount $account, ?GoogleAdsPeriod $period = null): int
    {
        return count($this->facts->facts($account, $period));
    }

    /**
     * @return array{count: int, items: list<array{provider: string, title: string, evidence_lines: array<int, string>, action_label: string, url: ?string, tone: string, rank_spend_micros: int}>}
     */
    public function top(MetaAdsAccount $account, string $workspaceUid, string $businessUid, bool $hasModule, int $limit = 5, ?GoogleAdsPeriod $period = null): array
    {
        $facts = $this->facts->facts($account, $period);
        $items = [];

        foreach (array_slice($facts, 0, max(0, $limit)) as $fact) {
            $presented = $this->presenter->present($fact);

            $items[] = [
                'provider' => 'meta',
                'title' => $presented['title'],
                'evidence_lines' => $presented['evidence_lines'],
                'action_label' => $presented['action_label'],
                'url' => $this->url($presented['link_target'], $presented['target_uid'], $workspaceUid, $businessUid, $hasModule),
                'tone' => match ($fact->type) {
                    MetaAdsRecommendationType::StrongPerformer => 'success',
                    MetaAdsRecommendationType::ZeroResultSpend,
                    MetaAdsRecommendationType::CostPerResultAboveTarget,
                    MetaAdsRecommendationType::PacingOver,
                    MetaAdsRecommendationType::HighFrequencyWeakResults,
                    MetaAdsRecommendationType::DeliveryIssue => 'warning',
                    default => 'neutral',
                },
                'rank_spend_micros' => $fact->rankSpendMicros,
            ];
        }

        return ['count' => count($facts), 'items' => $items];
    }

    private function url(string $target, ?string $uid, string $workspaceUid, string $businessUid, bool $hasModule): ?string
    {
        $base = [$workspaceUid, $businessUid];

        if ($target === 'budget') {
            return Route::has(self::PREFIX . 'settings') ? route(self::PREFIX . 'settings', $base) : null;
        }

        if (! $hasModule) {
            return null;
        }

        $route = match ($target) {
            'campaign' => $uid === null ? null : [self::PREFIX . 'campaigns.show', [...$base, $uid]],
            'ad_set' => [self::PREFIX . 'ad-sets.index', $base],
            'ad' => [self::PREFIX . 'ads.index', $base],
            default => null,
        };

        return $route !== null && Route::has($route[0]) ? route($route[0], $route[1]) : null;
    }
}
