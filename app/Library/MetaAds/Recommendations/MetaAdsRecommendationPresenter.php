<?php

namespace App\Library\MetaAds\Recommendations;

use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\Reporting\MetaAdsMetricTotals;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §12 — the ONLY place Meta recommendation
 * wording lives. Turns a deterministic fact into plain, calm, owner-friendly
 * copy: a title, evidence lines, the fixed basis line and an action label.
 * All numbers come from the fact's evidence (never computed or invented here);
 * money is formatted by MetaAdsMoney in the account currency.
 *
 * Wording rules: observations only (what Meta recorded), never a cause, never
 * an alarm word, nothing is "wasted", and no search-term / keyword language
 * (Meta has none).
 *
 * `link_target` is a stable key the UI maps to a route: campaign | ad_set |
 * ad | budget (with `target_uid` for the first three). Output strings are
 * plain text (names are customer data): the view must escape them.
 */
final class MetaAdsRecommendationPresenter
{
    /** @return array{title: string, evidence_lines: array<int, string>, basis_line: string, action_label: string, link_target: string, target_uid: ?string} */
    public function present(MetaAdsRecommendationFact $fact): array
    {
        $e = $fact->evidence;
        $money = fn (mixed $micros): string => MetaAdsMoney::format($micros === null ? null : (int) $micros, $fact->currencyCode);
        $name = $fact->subjectName ?? 'This item';
        $window = $this->window($e);
        $result = (string) ($e['result_label'] ?? 'results');

        [$title, $lines, $label] = match ($fact->type) {
            MetaAdsRecommendationType::ZeroResultSpend => [
                sprintf('%s has spend but no results yet', $name),
                [
                    sprintf('%s spent %s with %s.', $name, $money($e['spend_micros'] ?? null), $this->clicks($e['link_clicks'] ?? null)),
                    sprintf('Meta recorded no "%s" %s.', $result, $window),
                ],
                'Review campaign',
            ],
            MetaAdsRecommendationType::CostPerResultAboveTarget => [
                sprintf('%s costs more per result than your target', $name),
                [
                    sprintf('Cost per result is %s against a target of %s %s.', $money($e['cost_per_result_micros'] ?? null), $money($e['target_cost_per_result_micros'] ?? null), $window),
                    sprintf('%s spent for %s %s.', $money($e['spend_micros'] ?? null), $this->results($e['results'] ?? null, $result), $window),
                ],
                'Review campaign',
            ],
            MetaAdsRecommendationType::StrongPerformer => [
                sprintf('%s is doing well against your target', $name),
                [
                    sprintf('Cost per result is %s, at or under your target of %s %s.', $money($e['cost_per_result_micros'] ?? null), $money($e['target_cost_per_result_micros'] ?? null), $window),
                    sprintf('%s from %s spent.', $this->results($e['results'] ?? null, $result), $money($e['spend_micros'] ?? null)),
                ],
                'Review campaign',
            ],
            MetaAdsRecommendationType::PacingOver => [
                'Spending is running ahead of your monthly budget',
                $this->pacingLines($e, $money, 'ahead of'),
                'Review budget',
            ],
            MetaAdsRecommendationType::PacingUnder => [
                'Spending is running behind your monthly budget',
                $this->pacingLines($e, $money, 'behind'),
                'Review budget',
            ],
            MetaAdsRecommendationType::HighFrequencyWeakResults => [
                sprintf('%s shows a high frequency and a higher cost per result', $name),
                $this->frequencyLines($e, $money, $result),
                'Review ad set',
            ],
            MetaAdsRecommendationType::DeliveryIssue => [
                sprintf('%s is active, and Meta reports a delivery issue', $name),
                [
                    sprintf('Meta reports its delivery status as "%s".', $this->statusText((string) ($e['effective_status'] ?? ''))),
                    ($e['spend_micros'] ?? null) !== null ? sprintf('%s was spent %s.', $money($e['spend_micros']), $window) : 'No spend was recorded for it in this period.',
                ],
                match ($e['entity_type'] ?? null) {
                    'ad_set' => 'Review ad set',
                    'ad' => 'Review ad',
                    default => 'Review campaign',
                },
            ],
        };

        return [
            'title' => $title,
            'evidence_lines' => array_values(array_filter($lines)),
            'basis_line' => sprintf('Based on your cached Meta Ads data for %s; deterministic rule.', $this->periodLabel($e)),
            'action_label' => $label,
            'link_target' => match ($fact->suggestedAction['key'] ?? null) {
                'review_ad_set' => 'ad_set',
                'review_ad' => 'ad',
                'review_budget' => 'budget',
                default => 'campaign',
            },
            'target_uid' => $fact->suggestedAction['target_uid'] ?? null,
        ];
    }

    /** @param  array<string, scalar|null>  $e */
    private function frequencyLines(array $e, callable $money, string $result): array
    {
        $frequency = isset($e['frequency_7d']) ? rtrim(rtrim(number_format((float) $e['frequency_7d'], 2, '.', ''), '0'), '.') : null;

        $lines = [
            $frequency !== null ? sprintf('Each person saw this ad set about %s times in the last 7 days.', $frequency) : null,
        ];

        if (($e['variant'] ?? null) === 'results_stopped') {
            $lines[] = sprintf('Meta recorded no "%s" in the last 7 days after %s in the 7 days before, with %s spent.', $result, $this->results($e['previous_results'] ?? null, $result), $money($e['spend_micros'] ?? null));
        } else {
            $lines[] = sprintf('Cost per result was %s in the last 7 days, compared with %s in the 7 days before.', $money($e['last_cost_per_result_micros'] ?? null), $money($e['previous_cost_per_result_micros'] ?? null));
        }

        return $lines;
    }

    /** @param  array<string, scalar|null>  $e */
    private function pacingLines(array $e, callable $money, string $direction): array
    {
        $elapsed = isset($e['elapsed_proportion']) ? (int) round(((float) $e['elapsed_proportion']) * 100) : null;
        $spent = isset($e['spend_proportion']) ? (int) round(((float) $e['spend_proportion']) * 100) : null;

        return [
            sprintf('%s spent so far this month against a monthly budget of %s.', $money($e['spend_micros'] ?? null), $money($e['monthly_target_micros'] ?? null)),
            $elapsed !== null && $spent !== null ? sprintf('%d%% of the budget used with %d%% of the month elapsed, which is %s pace.', $spent, $elapsed, $direction) : null,
            isset($e['projected_micros']) ? sprintf('At this rate the month would end around %s.', $money($e['projected_micros'])) : null,
        ];
    }

    /** @param  array<string, scalar|null>  $e */
    private function window(array $e): string
    {
        if (! isset($e['period_from'], $e['period_to'])) {
            return '';
        }

        return sprintf(
            'between %s and %s',
            CarbonImmutable::parse((string) $e['period_from'])->format('M j'),
            CarbonImmutable::parse((string) $e['period_to'])->format('M j'),
        );
    }

    /** @param  array<string, scalar|null>  $e */
    private function periodLabel(array $e): string
    {
        return match ($e['period_key'] ?? null) {
            GoogleAdsPeriod::LAST_7 => 'the last 7 days',
            GoogleAdsPeriod::LAST_30 => 'the last 30 days',
            GoogleAdsPeriod::THIS_MONTH => 'this month',
            GoogleAdsPeriod::PREVIOUS_MONTH => 'last month',
            default => $this->window($e) !== '' ? str_replace('between ', '', $this->window($e)) : 'the selected period',
        };
    }

    private function statusText(string $status): string
    {
        return match ($status) {
            'WITH_ISSUES' => 'with issues',
            'DISAPPROVED' => 'disapproved',
            'PENDING_BILLING_INFO' => 'pending billing info',
            default => strtolower(str_replace('_', ' ', $status)),
        };
    }

    private function clicks(mixed $clicks): string
    {
        return $clicks === null ? 'no click data' : sprintf('%d link %s', (int) $clicks, ((int) $clicks) === 1 ? 'click' : 'clicks');
    }

    private function results(mixed $results, string $label): string
    {
        if ($results === null) {
            return 'no results';
        }

        $text = (string) MetaAdsMetricTotals::trim((string) $results);

        return sprintf('%s "%s"', $text, $label);
    }
}
