<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * Google + Meta Ads rules over the Ads modules' own deterministic recommendation facts. Every
 * rule is Business-wide and read-only (it opens the Ads page; nothing is changed, paused or
 * spent from here). Provider-specific rules judge only the provider that can produce the fact,
 * so a Google-only Business is never "passing" a Meta delivery check.
 *
 * Score safety: the six performance rules are INSUFFICIENT (excluded, neutral) until a provider
 * has settled and is fresh. The two connection rules sit in the unscored Connections category.
 */
final class AdsRules extends AbstractGrowthRule
{
    public const ZERO_SPEND = 'zero_spend';
    public const CPL_ABOVE = 'cpl_above';
    public const PACING_OVER = 'pacing_over';
    public const TERM_WASTE = 'term_waste';
    public const DELIVERY = 'delivery_issue';
    public const FATIGUE = 'fatigue';
    public const STALE = 'stale';
    public const CONNECTION = 'connection';

    public function __construct(private readonly string $kind = self::ZERO_SPEND)
    {
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(fn (string $k) => new self($k), [
            self::ZERO_SPEND, self::CPL_ABOVE, self::PACING_OVER, self::TERM_WASTE, self::DELIVERY, self::FATIGUE, self::STALE, self::CONNECTION,
        ]);
    }

    public function definition(): GrowthRuleDefinition
    {
        $perf = fn (string $key, string $title, string $summary, string $factKey, string $evidence, string $label, int $weight, string $why, string $expected, string $actionKey) => new GrowthRuleDefinition(
            key: $key,
            worker: OpportunityWorkerKey::Ads,
            category: GrowthCategory::Ads,
            sourceModule: 'ads',
            domain: 'ads',
            scope: 'business',
            title: $title,
            summary: $summary,
            factKey: $factKey,
            evidenceSummary: $evidence,
            actionKey: $actionKey,
            actionLabel: $label,
            target: 'ads',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: $weight,
            minSample: 1,
            why: $why,
            expected: $expected,
            goalKeys: [BusinessGoal::LeadGeneration->value],
        );

        return match ($this->kind) {
            self::CPL_ABOVE => $perf('ads.cpl_above_target:v1', 'Leads from ads cost more than your target', 'Some campaigns are bringing results at a higher cost than the target you set.', 'ads_cost_above_target', 'Campaigns whose cost per lead or result is above the owner-set target.', 'Review ads', 3, 'When each lead costs more than you planned, the same budget buys fewer customers.', 'Reviewing those campaigns shows what is driving the cost. Nothing is changed for you.', 'growth_review_ads_cost'),
            self::PACING_OVER => $perf('ads.budget_over_pacing:v1', 'Ad spend is running ahead of budget', 'This month\'s ad spend is on course to go past the budget you set.', 'ads_over_pacing', 'Month-to-date spend projecting above the monthly target.', 'Review ad budget', 2, 'Spending faster than planned can use up the month\'s budget before the month ends.', 'Reviewing the budget lets you decide whether to slow down. Nothing is changed for you.', 'growth_review_ads_budget'),
            self::TERM_WASTE => $perf('ads.search_term_waste:v1', 'Ads are spending on searches that are not bringing results', 'Some search terms have used budget without producing results.', 'ads_search_term_waste', 'Search terms with spend and no results that no negative keyword already covers.', 'Review search terms', 2, 'Paying for searches that never lead to a customer takes budget from the ones that do.', 'Reviewing the terms lets you exclude the wasteful ones. Nothing is changed for you.', 'growth_review_search_terms'),
            self::DELIVERY => $perf('ads.delivery_issue:v1', 'An ad is not delivering properly', 'Meta reports a problem with an ad that is switched on.', 'ads_delivery_issue', 'Active Meta campaigns, ad sets or ads that Meta reports as having issues.', 'Review ads', 3, 'An ad that is on but blocked is not reaching customers while you may still be waiting on it.', 'Fixing the issue Meta reports gets the ad delivering again. Nothing is changed for you.', 'growth_review_ads_delivery'),
            self::FATIGUE => $perf('ads.high_frequency_weak_results:v1', 'An ad audience may be getting tired of your ad', 'The same people are seeing an ad often and results have weakened.', 'ads_audience_fatigue', 'Ad sets with high frequency whose cost per result worsened or whose results stopped.', 'Review ads', 1, 'When the same people see an ad many times, results usually fade.', 'Refreshing the ad or audience can bring results back. Nothing is changed for you.', 'growth_review_ads_fatigue'),
            self::STALE => new GrowthRuleDefinition(
                key: 'ads.sync_stale:v1',
                worker: OpportunityWorkerKey::Ads,
                category: GrowthCategory::Connections,
                sourceModule: 'ads',
                domain: 'ads',
                scope: 'business',
                title: 'Your ad numbers have not updated recently',
                summary: 'The last refresh from your ad account did not complete, so recommendations are paused.',
                factKey: 'ads_sync_stale',
                evidenceSummary: 'A connected ad account whose data refresh is overdue or failing.',
                actionKey: 'growth_check_ads_connection',
                actionLabel: 'Open ads',
                target: 'ads',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 1,
                minSample: 1,
                why: 'Ad advice is only as good as the latest numbers. Until they refresh, nothing here is judged.',
                expected: 'Once the account refreshes, recommendations resume on their own.',
                goalKeys: [BusinessGoal::LeadGeneration->value],
            ),
            self::CONNECTION => new GrowthRuleDefinition(
                key: 'ads.connection_needed:v1',
                worker: OpportunityWorkerKey::Ads,
                category: GrowthCategory::Connections,
                sourceModule: 'ads',
                domain: 'ads',
                scope: 'business',
                title: 'Connect an ad account to see how your ads perform',
                summary: 'Your plan includes Ads, but no ad account is connected (or its connection needs renewing).',
                factKey: 'ads_connection_needed',
                evidenceSummary: 'The Business has the Ads module and no usable Google or Meta connection.',
                actionKey: 'growth_connect_ads',
                actionLabel: 'Open ads',
                target: 'ads',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 1,
                minSample: 1,
                why: 'Without a connected account there is nothing to measure, so no ad advice can be given.',
                expected: 'Connecting lets the numbers flow in; you choose which account. Nothing is spent or changed.',
                goalKeys: [BusinessGoal::LeadGeneration->value],
            ),
            default => $perf('ads.zero_conversion_spend:v1', 'Ads are spending without bringing results', 'Some active campaigns have used real budget and produced no leads or results.', 'ads_zero_result_spend', 'Active campaigns with meaningful spend and no recorded results.', 'Review ads', 3, 'Budget that produces nothing is the quickest thing to stop or fix.', 'Reviewing those campaigns shows whether to fix or pause them. Nothing is changed for you.', 'growth_review_zero_result_ads'),
        };
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $ads = $facts->set('ads');

        if ($this->kind === self::CONNECTION) {
            return $this->detectConnection($ads);
        }

        if ($this->kind === self::STALE) {
            return $ads->get('stale') && $ads->get('connected')
                ? [0 => ['impact' => 2, 'urgency' => 2, 'effort' => 1, 'confidence' => 1.0, 'evidence' => ['count' => 1]]]
                : [];
        }

        if (! $this->judgeable($facts)) {
            return [];
        }

        [$countKey, $moneyKey, $impact, $urgency, $effort] = match ($this->kind) {
            self::CPL_ABOVE => ['cpl_above', 'cpl_above', 4, 3, 2],
            self::PACING_OVER => ['pacing_over', null, 3, 3, 1],
            self::TERM_WASTE => ['term_waste', 'term_waste', 3, 2, 1],
            self::DELIVERY => ['delivery_issue', null, 4, 5, 1],
            self::FATIGUE => ['fatigue', null, 3, 2, 2],
            default => ['zero_spend', 'zero_spend', 4, 4, 2],
        };

        $count = (int) ($ads->get('counts')[$countKey] ?? 0);

        if ($count === 0) {
            return [];
        }

        $money = $moneyKey === null ? null : ($ads->get('money')[$moneyKey] ?? null);

        return [0 => [
            'impact' => $this->impactForValue($impact, $money['minor'] ?? null, $facts),
            'urgency' => $urgency,
            'effort' => $effort,
            'confidence' => 1.0,
            'evidence' => ['count' => $count, 'value_minor' => $money['minor'] ?? null, 'currency' => $money['currency'] ?? null],
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function detectConnection($ads): array
    {
        $states = $ads->get('providers', []);

        if ($ads->get('connected') || $states === []) {
            return [];
        }

        return [0 => ['impact' => 2, 'urgency' => 1, 'effort' => 2, 'confidence' => 1.0, 'evidence' => ['count' => 1]]];
    }

    /** The performance rules judge only a settled, fresh provider that CAN produce their fact. */
    private function judgeable(GrowthFactSnapshot $facts): bool
    {
        $ads = $facts->set('ads');
        $by = $ads->get('sufficient_by', []);

        return match ($this->kind) {
            self::TERM_WASTE => (bool) ($by['google'] ?? false),
            self::DELIVERY, self::FATIGUE => (bool) ($by['meta'] ?? false),
            default => (bool) $ads->get('sufficient'),
        };
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return match ($this->kind) {
            self::STALE, self::CONNECTION => 1,
            default => $this->judgeable($facts) ? 1 : 0,
        };
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        if ($this->kind !== self::CPL_ABOVE) {
            return null;
        }

        $strong = (int) ($facts->set('ads')->get('counts')['strong'] ?? 0);

        return $strong > 0 ? ['strong' => $strong] : null;
    }

    public function headline(array $evidence): string
    {
        $n = (int) ($evidence['count'] ?? 0);
        $money = GrowthMoney::format($evidence['value_minor'] ?? null, $evidence['currency'] ?? null);
        $plural = $n === 1 ? '' : 's';
        $has = $n === 1 ? 'has' : 'have';
        $spent = $money === null ? '' : ' — ' . $money . ' spent';

        return match ($this->kind) {
            self::CPL_ABOVE => sprintf('%d campaign%s %s a cost per result above your target%s.', $n, $plural, $has, $spent),
            self::PACING_OVER => 'Ad spend this month is on course to pass your budget.',
            self::TERM_WASTE => sprintf('%d search term%s used budget without results%s.', $n, $plural, $spent),
            self::DELIVERY => sprintf('%d ad item%s %s a delivery problem reported by Meta.', $n, $plural, $has),
            self::FATIGUE => sprintf('%d ad set%s show%s signs of a tired audience.', $n, $plural, $n === 1 ? 's' : ''),
            self::STALE => 'Your ad account has not refreshed recently, so ad advice is paused.',
            self::CONNECTION => 'No ad account is connected yet.',
            default => sprintf('%d active campaign%s spent budget with no results%s.', $n, $plural, $spent),
        };
    }

    public function positiveStatement(array $positive): ?string
    {
        $n = (int) ($positive['strong'] ?? 0);

        return $n > 0
            ? sprintf('%d campaign%s %s getting results at or below your target cost.', $n, $n === 1 ? '' : 's', $n === 1 ? 'is' : 'are')
            : null;
    }
}
