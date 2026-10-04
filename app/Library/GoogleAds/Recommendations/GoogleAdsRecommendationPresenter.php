<?php

namespace App\Library\GoogleAds\Recommendations;

use App\Library\GoogleAds\GoogleAdsMoney;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §12 — the ONLY place recommendation wording
 * lives. Turns a deterministic fact into plain, calm copy: a title, evidence
 * lines and an action label. All numbers come from the fact's evidence (never
 * computed or invented here); money is formatted by GoogleAdsMoney in the
 * account currency. No alarm words: "potential wasted spend", "worth a look".
 *
 * Output strings are plain text (a campaign or search term is customer data):
 * the view must escape them.
 */
final class GoogleAdsRecommendationPresenter
{
    /** @return array{title: string, evidence_lines: array<int, string>, action_label: string} */
    public function present(GoogleAdsRecommendationFact $fact): array
    {
        $e = $fact->evidence;
        $money = fn (mixed $micros): string => GoogleAdsMoney::format($micros === null ? null : (int) $micros, $fact->currencyCode);
        $name = $fact->subjectName ?? 'This campaign';
        $window = $this->window($e);

        return match ($fact->type) {
            GoogleAdsRecommendationType::WastedSearchTerms => [
                'title' => 'Potential wasted spend on search terms',
                'evidence_lines' => array_values(array_filter([
                    sprintf('%s spent on %d search %s with no conversions %s.', $money($e['wasted_spend_micros'] ?? null), (int) ($e['term_count'] ?? 0), ((int) ($e['term_count'] ?? 0)) === 1 ? 'term' : 'terms', $window),
                    isset($e['top_term']) ? sprintf('Biggest: "%s" - %s spent, %d clicks.', $e['top_term'], $money($e['top_term_spend_micros'] ?? null), (int) ($e['top_term_clicks'] ?? 0)) : null,
                    ((int) ($e['already_excluded_count'] ?? 0)) > 0 ? sprintf('%d more %s already excluded.', (int) $e['already_excluded_count'], ((int) $e['already_excluded_count']) === 1 ? 'term is' : 'terms are') : null,
                ])),
                'action_label' => 'Review search terms',
            ],
            GoogleAdsRecommendationType::ZeroConversionCampaign => [
                'title' => sprintf('%s has spend but no conversions yet', $name),
                'evidence_lines' => [
                    sprintf('%s spent %s with %s.', $name, $money($e['spend_micros'] ?? null), $this->clicks($e['clicks'] ?? null)),
                    sprintf('Google recorded no conversions %s.', $window),
                ],
                'action_label' => 'Review campaign',
            ],
            GoogleAdsRecommendationType::CplAboveTarget => [
                'title' => sprintf('%s costs more per conversion than your target', $name),
                'evidence_lines' => [
                    sprintf('Cost per conversion is %s against a target of %s %s.', $money($e['cpl_micros'] ?? null), $money($e['target_cpl_micros'] ?? null), $window),
                    sprintf('%s spent for %s %s.', $money($e['spend_micros'] ?? null), $this->conversions($e['conversions'] ?? null), $window),
                ],
                'action_label' => 'Review campaign',
            ],
            GoogleAdsRecommendationType::StrongCampaign => [
                'title' => sprintf('%s is doing well against your target', $name),
                'evidence_lines' => [
                    sprintf('Cost per conversion is %s, at or under your target of %s %s.', $money($e['cpl_micros'] ?? null), $money($e['target_cpl_micros'] ?? null), $window),
                    sprintf('%s from %s spent.', $this->conversions($e['conversions'] ?? null), $money($e['spend_micros'] ?? null)),
                ],
                'action_label' => 'Review campaign',
            ],
            GoogleAdsRecommendationType::PacingOver => [
                'title' => 'Spending is running ahead of your monthly budget',
                'evidence_lines' => $this->pacingLines($e, $money, 'ahead of'),
                'action_label' => 'Review budget',
            ],
            GoogleAdsRecommendationType::PacingUnder => [
                'title' => 'Spending is running behind your monthly budget',
                'evidence_lines' => $this->pacingLines($e, $money, 'behind'),
                'action_label' => 'Review budget',
            ],
        };
    }

    /** @param  array<string, scalar|null>  $e */
    private function pacingLines(array $e, callable $money, string $direction): array
    {
        $elapsed = isset($e['elapsed_proportion']) ? (int) round(((float) $e['elapsed_proportion']) * 100) : null;
        $spent = isset($e['spend_proportion']) ? (int) round(((float) $e['spend_proportion']) * 100) : null;

        return array_values(array_filter([
            sprintf('%s spent so far this month against a monthly budget of %s.', $money($e['spend_micros'] ?? null), $money($e['monthly_target_micros'] ?? null)),
            $elapsed !== null && $spent !== null ? sprintf('%d%% of the budget used with %d%% of the month elapsed, which is %s pace.', $spent, $elapsed, $direction) : null,
            isset($e['projected_micros']) ? sprintf('At this rate the month would end around %s.', $money($e['projected_micros'])) : null,
        ]));
    }

    /** @param  array<string, scalar|null>  $e */
    private function window(array $e): string
    {
        if (! isset($e['period_from'], $e['period_to'])) {
            return '';
        }

        $from = CarbonImmutable::parse((string) $e['period_from']);
        $to = CarbonImmutable::parse((string) $e['period_to']);

        return sprintf('between %s and %s', $from->format('M j'), $to->format('M j'));
    }

    private function clicks(mixed $clicks): string
    {
        return $clicks === null ? 'no click data' : sprintf('%d %s', (int) $clicks, ((int) $clicks) === 1 ? 'click' : 'clicks');
    }

    private function conversions(mixed $conversions): string
    {
        if ($conversions === null) {
            return 'no conversions';
        }

        $text = (string) \App\Library\GoogleAds\Reporting\GoogleAdsMetricTotals::trim((string) $conversions);
        $text = $text === '' ? '0' : $text;

        return $text === '1' ? '1 conversion' : $text . ' conversions';
    }
}
