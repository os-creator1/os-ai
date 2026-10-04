<?php

namespace App\Library\Ads;

use App\Library\GoogleAds\GoogleAdsMoney;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationPresenter;
use App\Library\GoogleAds\Reporting\GoogleAdsOverviewReader;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Sync\GoogleAdsFreshness;
use App\Library\MetaAds\MetaAdsDisplay;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\Recommendations\MetaAdsAttentionItems;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\MetaAds\Sync\MetaAdsFreshness;
use App\Models\GoogleAdsAccount;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Facades\Route;

/**
 * Meta Ads Module V1 (contract 24 §9) — the cross-channel Ads Overview data.
 *
 * Per connected channel: THIS MONTH's spend, results with the channel's OWN
 * label and definition, the channel's own cost per result, pacing status,
 * issue count (deterministic facts) and data freshness. Nothing is blended:
 *
 *   - TOTAL spend appears only when two or more channels are shown, every one
 *     reports spend and all share ONE currency code; otherwise per-channel
 *     spend only and `mixedCurrencies` explains why;
 *   - results are never added across channels (Google conversions and Meta
 *     results are different things), no blended cost per result, no summed
 *     or split targets;
 *   - the merged "What needs attention" list interleaves each provider's own
 *     ranked facts (spend ranks are in different currencies and are never
 *     compared), labelled by provider, capped at ATTENTION_LIMIT.
 *
 * Cached data only (no provider call). Query cost is bounded per channel: one
 * overview read, one freshness read and one fact read, regardless of rows.
 * Google facts are read only when the viewer has the FULL module (Google's
 * Recommendations page is module-only); Meta facts need only view_meta_ads.
 */
final class AdsChannelOverviewReader
{
    public const ATTENTION_LIMIT = 5;

    public function __construct(
        private readonly GoogleAdsOverviewReader $googleOverview,
        private readonly GoogleAdsFreshness $googleFreshness,
        private readonly GoogleAdsRecommendationFactReader $googleFacts,
        private readonly GoogleAdsRecommendationPresenter $googlePresenter,
        private readonly MetaAdsOverviewReader $metaOverview,
        private readonly MetaAdsFreshness $metaFreshness,
        private readonly MetaAdsAttentionItems $metaAttention,
    ) {
    }

    /**
     * @param  array{visible: bool, state: string, account: ?GoogleAdsAccount}  $google  state: not_connected | no_account | ready
     * @param  array{visible: bool, state: string, account: ?MetaAdsAccount}  $meta  state: not_connected | expired | no_account | ready
     * @return array{channels: list<array<string, mixed>>, total: ?array{spend_micros: int, currency: string}, mixedCurrencies: bool, attention: list<array<string, mixed>>}
     */
    public function read(string $workspaceUid, string $businessUid, array $google, array $meta, bool $hasModule): array
    {
        $channels = [];
        $googleFacts = [];
        $metaAttention = ['count' => 0, 'items' => []];

        if ($google['visible']) {
            if ($google['state'] === 'ready' && $google['account'] instanceof GoogleAdsAccount) {
                $channel = $this->googleChannel($google['account'], $workspaceUid, $businessUid, $hasModule);
                $googleFacts = $channel['_attention'];
                unset($channel['_attention']);
                $channels[] = $channel;
            } else {
                $channels[] = $this->notReady('google', 'Google', $google['state'], $workspaceUid, $businessUid);
            }
        }

        if ($meta['visible']) {
            if ($meta['state'] === 'ready' && $meta['account'] instanceof MetaAdsAccount) {
                $channel = $this->metaChannel($meta['account'], $workspaceUid, $businessUid, $hasModule);
                $metaAttention = $channel['_attention'];
                unset($channel['_attention']);
                $channels[] = $channel;
            } else {
                $channels[] = $this->notReady('meta', 'Meta', $meta['state'], $workspaceUid, $businessUid);
            }
        }

        $ready = array_values(array_filter($channels, static fn (array $channel): bool => $channel['ready']));
        $currencies = array_values(array_unique(array_map(static fn (array $channel): string => (string) $channel['currency'], $ready)));
        $mixed = count($ready) >= 2 && count($currencies) > 1;
        $total = null;

        if (count($ready) >= 2 && count($currencies) === 1
            && count(array_filter($ready, static fn (array $channel): bool => $channel['spend_micros'] === null)) === 0) {
            $total = [
                'spend_micros' => array_sum(array_map(static fn (array $channel): int => (int) $channel['spend_micros'], $ready)),
                'currency' => $currencies[0],
            ];
        }

        return [
            'channels' => $channels,
            'total' => $total,
            'mixedCurrencies' => $mixed,
            'attention' => $this->mergeAttention($googleFacts, $metaAttention['items']),
        ];
    }

    /** @return array<string, mixed> */
    private function googleChannel(GoogleAdsAccount $account, string $workspaceUid, string $businessUid, bool $hasModule): array
    {
        $period = GoogleAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account);
        $overview = $this->googleOverview->read($account, $period);
        $freshness = $this->googleFreshness->for($account);
        $currency = (string) $account->currency_code;
        $attention = [];
        $issues = null;

        if ($hasModule) {
            $facts = $this->googleFacts->facts($account);
            $issues = count($facts);

            foreach (array_slice($facts, 0, self::ATTENTION_LIMIT) as $fact) {
                $presented = $this->googlePresenter->present($fact);
                $attention[] = [
                    'provider' => 'google',
                    'title' => $presented['title'],
                    'evidence_lines' => $presented['evidence_lines'],
                    'action_label' => $presented['action_label'],
                    'url' => $this->googleUrl($fact->suggestedAction, $workspaceUid, $businessUid),
                    'tone' => 'neutral',
                ];
            }
        }

        return [
            'key' => 'google',
            'label' => 'Google',
            'ready' => true,
            'state' => 'ready',
            'currency' => $currency,
            'spend_micros' => $overview->spendMicros,
            'spend_display' => GoogleAdsMoney::format($overview->spendMicros, $currency),
            'results_label' => 'Google conversions',
            'results_definition' => 'The conversion actions you set up in Google Ads, counted by Google.',
            'results' => $overview->googleConversions === null ? null : MetaAdsDisplay::count($overview->googleConversions),
            'results_unset' => false,
            'cost_per_result_label' => 'Cost per conversion',
            'cost_per_result_display' => GoogleAdsMoney::format($overview->cplMicros, $currency),
            'pacing_status' => $overview->pacing->status->value,
            'pacing_text' => $this->pacingText($overview->pacing->status),
            'issue_count' => $issues,
            'updated_at' => $freshness->lastSuccessfulSyncAt,
            'data_through' => $freshness->dataThroughDate,
            'freshness_warn' => $freshness->shouldWarn(),
            'url' => Route::has('customer.workspaces.businesses.ads.index') ? route('customer.workspaces.businesses.ads.index', [$workspaceUid, $businessUid]) : null,
            'settings_url' => null,
            '_attention' => $attention,
        ];
    }

    /** @return array<string, mixed> */
    private function metaChannel(MetaAdsAccount $account, string $workspaceUid, string $businessUid, bool $hasModule): array
    {
        $period = MetaAdsPeriod::resolve(GoogleAdsPeriod::THIS_MONTH, $account);
        $overview = $this->metaOverview->read($account, $period);
        $freshness = $this->metaFreshness->for($account);
        $currency = (string) $account->currency_code;
        $attention = $this->metaAttention->top($account, $workspaceUid, $businessUid, $hasModule, self::ATTENTION_LIMIT);

        $label = $overview->resultTypeLabel;

        return [
            'key' => 'meta',
            'label' => 'Meta',
            'ready' => true,
            'state' => 'ready',
            'currency' => $currency,
            'spend_micros' => $overview->spendMicros,
            'spend_display' => MetaAdsMoney::format($overview->spendMicros, $currency),
            'results_label' => $label === null ? 'Meta results' : 'Meta results (' . $label . ')',
            'results_definition' => 'The one kind of action you chose in Meta settings, counted by Meta.',
            'results' => $overview->resultTypeUnset ? null : MetaAdsDisplay::count($overview->results),
            'results_unset' => $overview->resultTypeUnset,
            'cost_per_result_label' => 'Cost per result',
            'cost_per_result_display' => MetaAdsMoney::format($overview->costPerResultMicros, $currency),
            'pacing_status' => $overview->pacing->status->value,
            'pacing_text' => $this->pacingText($overview->pacing->status),
            'issue_count' => $attention['count'],
            'updated_at' => $freshness->lastSuccessfulSyncAt,
            'data_through' => $freshness->dataThroughDate,
            'freshness_warn' => $freshness->shouldWarn(),
            'url' => Route::has('customer.workspaces.businesses.ads.meta.index') ? route('customer.workspaces.businesses.ads.meta.index', [$workspaceUid, $businessUid]) : null,
            'settings_url' => Route::has('customer.workspaces.businesses.ads.meta.settings') ? route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) : null,
            '_attention' => $attention,
        ];
    }

    /** @return array<string, mixed> */
    private function notReady(string $key, string $label, string $state, string $workspaceUid, string $businessUid): array
    {
        $route = $key === 'google' ? 'customer.workspaces.businesses.ads.index' : 'customer.workspaces.businesses.ads.meta.index';
        $reconnect = $state === 'expired';

        return [
            'key' => $key,
            'label' => $label,
            'ready' => false,
            'state' => $state,
            'currency' => null,
            'spend_micros' => null,
            'cta_label' => match (true) {
                $reconnect => 'Reconnect ' . $label,
                $state === 'no_account' => 'Choose a ' . $label . ' ad account',
                default => 'Connect ' . $label . ' Ads',
            },
            'cta_url' => Route::has($route) ? route($route, [$workspaceUid, $businessUid]) : null,
        ];
    }

    private function pacingText(GoogleAdsPacingStatus $status): string
    {
        return match ($status) {
            GoogleAdsPacingStatus::OnPace => 'On pace',
            GoogleAdsPacingStatus::Ahead => 'Spending ahead of budget',
            GoogleAdsPacingStatus::Behind => 'Spending behind budget',
            GoogleAdsPacingStatus::NoTarget => 'No monthly target set',
            GoogleAdsPacingStatus::InsufficientData => 'Not enough data to judge pacing',
        };
    }

    /**
     * @param  array{key: string, target_uid: ?string}  $action
     */
    private function googleUrl(array $action, string $workspaceUid, string $businessUid): ?string
    {
        $prefix = 'customer.workspaces.businesses.ads.';
        $base = [$workspaceUid, $businessUid];

        $target = match ($action['key']) {
            'review_search_terms' => [$prefix . 'search-terms.index', $base + ['class' => 'potential_waste']],
            'review_campaign' => $action['target_uid'] === null ? null : [$prefix . 'campaigns.show', [...$base, $action['target_uid']]],
            'review_budget' => [$prefix . 'budget', $base],
            default => null,
        };

        return $target !== null && Route::has($target[0]) ? route($target[0], $target[1]) : null;
    }

    /**
     * Round-robin Google / Meta so neither provider crowds out the other and
     * no spend (different currencies) is ever compared across providers.
     *
     * @param  list<array<string, mixed>>  $google
     * @param  list<array<string, mixed>>  $meta
     * @return list<array<string, mixed>>
     */
    private function mergeAttention(array $google, array $meta): array
    {
        $merged = [];

        for ($i = 0; count($merged) < self::ATTENTION_LIMIT && ($i < count($google) || $i < count($meta)); $i++) {
            foreach ([$google[$i] ?? null, $meta[$i] ?? null] as $item) {
                if ($item !== null && count($merged) < self::ATTENTION_LIMIT) {
                    $merged[] = $item;
                }
            }
        }

        return $merged;
    }
}
