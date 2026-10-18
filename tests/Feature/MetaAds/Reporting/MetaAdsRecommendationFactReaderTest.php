<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFact;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFactReader;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationPresenter;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationType;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * Contract 24 section 12 - every deterministic rule: fires, does not fire at
 * the threshold edge, insufficient data, no result type chosen, NULL vs 0,
 * currency. Clock pinned to 2026-10-04 12:00 UTC (08:00 Oct 4 in New York).
 *
 * Defaults under test (config/meta_ads.php): zero_result_min_spend 50, cpr_over_factor 1.25,
 * strong_min_results 3, frequency_threshold 3.0, fatigue worsening 1.3, fatigue_min_results 3,
 * fatigue_min_spend 20, pacing min_days 7 / tolerance 15%.
 */
class MetaAdsRecommendationFactReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAdsReportingData;

    private MetaAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinMetaClock();
        [, $business] = $this->metaReportingTenant();
        $this->account = $this->metaReportingAccountFor($business, ['target_cost_per_result_micros' => 10_000_000]);
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    /** @return array<int, MetaAdsRecommendationFact> */
    private function facts(?MetaAdsRecommendationType $type = null, ?string $period = null): array
    {
        $account = $this->account->fresh();
        $facts = app(MetaAdsRecommendationFactReader::class)->facts($account, $period === null ? null : MetaAdsPeriod::resolve($period, $account));

        return $type === null ? $facts : array_values(array_filter($facts, fn ($f) => $f->type === $type));
    }

    private function campaignWith(string $name, int $spend, string|int|null $results, string $day = '2026-10-02', array $overrides = []): \App\Models\MetaAdsCampaign
    {
        $campaign = $this->seedMetaCampaign($this->account, $name, $overrides);
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $campaign->id, $day, $spend);

        if ($results !== null) {
            $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, $day, $results);
        }

        return $campaign;
    }

    // ---- zero_result_spend ------------------------------------------------------------------

    public function test_zero_result_spend_fires_at_the_threshold_and_not_below(): void
    {
        $this->campaignWith('Has results', 30_000_000, 3);              // makes result data present
        $edge = $this->campaignWith('Edge', 50_000_000, null);
        $below = $this->campaignWith('Below', 49_999_999, null);

        $facts = $this->facts(MetaAdsRecommendationType::ZeroResultSpend);

        $this->assertCount(1, $facts);
        $this->assertSame($edge->uid, $facts[0]->subjectUid);
        $this->assertNotSame($below->uid, $facts[0]->subjectUid);
        $this->assertSame('0', $facts[0]->evidence['results']);
        $this->assertSame(50_000_000, $facts[0]->evidence['spend_micros']);
        $this->assertSame('rule:zero_results_min_spend', $facts[0]->factualBasis);
        $this->assertSame(['key' => 'review_campaign', 'target_uid' => $edge->uid], $facts[0]->suggestedAction);
    }

    public function test_zero_result_spend_needs_result_data_to_exist_null_is_not_zero(): void
    {
        // Spend, a chosen type, but Meta stored no result rows anywhere: cannot tell zero from "not stored".
        $this->campaignWith('Silent', 80_000_000, null);

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::ZeroResultSpend));
    }

    public function test_an_explicit_zero_result_row_counts_as_result_data(): void
    {
        $this->campaignWith('Explicit zero', 80_000_000, '0');

        $this->assertCount(1, $this->facts(MetaAdsRecommendationType::ZeroResultSpend));
    }

    public function test_result_based_rules_need_a_chosen_result_type(): void
    {
        $this->campaignWith('Has results', 30_000_000, 3);
        $this->campaignWith('Zero', 90_000_000, null);
        $this->campaignWith('Costly', 40_000_000, 2);
        $this->account->update(['result_action_type' => null]);

        $this->assertSame([], $this->facts());
    }

    public function test_results_of_another_action_type_are_never_reinterpreted(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Wrong type');
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-02', 90_000_000);
        $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-02', 9, null, 'link_click');

        // The chosen type has no rows at all: unavailable, not "zero results".
        $this->assertSame([], $this->facts());

        $this->account->update(['result_action_type' => 'link_click']);
        $facts = $this->facts();
        $this->assertSame([MetaAdsRecommendationType::StrongPerformer], array_map(fn ($f) => $f->type, $facts)); // 9 results at 10M = target
    }

    public function test_paused_campaigns_produce_no_campaign_facts(): void
    {
        $this->campaignWith('Has results', 30_000_000, 3);
        $this->campaignWith('Paused zero', 90_000_000, null, '2026-10-02', ['status' => 'PAUSED']);

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::ZeroResultSpend));
    }

    // ---- cost_per_result_above_target / strong_performer -------------------------------------

    public function test_cost_per_result_above_target_edge_is_strict(): void
    {
        $edge = $this->campaignWith('Exactly 12.5', 12_500_000, 1);      // == target x 1.25: not "above"
        $over = $this->campaignWith('Just over', 12_500_001, 1);

        $facts = $this->facts(MetaAdsRecommendationType::CostPerResultAboveTarget);

        $this->assertCount(1, $facts);
        $this->assertSame($over->uid, $facts[0]->subjectUid);
        $this->assertNotSame($edge->uid, $facts[0]->subjectUid);
        $this->assertSame(12_500_001, $facts[0]->evidence['cost_per_result_micros']);
        $this->assertSame(10_000_000, $facts[0]->evidence['target_cost_per_result_micros']);
        $this->assertSame(1.25, $facts[0]->evidence['threshold_factor']);
    }

    public function test_cost_per_result_rules_need_a_target(): void
    {
        $this->campaignWith('Costly', 90_000_000, 3);
        $this->campaignWith('Cheap', 3_000_000, 3);
        $this->account->update(['target_cost_per_result_micros' => null]);

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::CostPerResultAboveTarget));
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::StrongPerformer));
    }

    public function test_strong_performer_edges(): void
    {
        $strong = $this->campaignWith('Strong', 30_000_000, 3);              // cpr == target, 3 results: strong
        $fewResults = $this->campaignWith('Too few', 20_000_000, 2);         // cpr == target but only 2 results
        $slightlyOver = $this->campaignWith('Slightly over', 30_000_002, 3); // 10_000_001 > target, below 12.5M: neither

        $facts = $this->facts(MetaAdsRecommendationType::StrongPerformer);

        $this->assertCount(1, $facts);
        $this->assertSame($strong->uid, $facts[0]->subjectUid);
        $this->assertSame('3', $facts[0]->evidence['results']);
        $this->assertSame([], array_filter($this->facts(), fn ($f) => in_array($f->subjectUid, [$fewResults->uid, $slightlyOver->uid], true)));
    }

    public function test_rules_use_the_selected_period(): void
    {
        // Spend only in the previous month: nothing in last_30? (Sep 5 .. Oct 4) - Sep 2 is outside.
        $this->campaignWith('Old', 90_000_000, null, '2026-09-02');
        $this->campaignWith('Old results', 90_000_000, 3, '2026-09-02');

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::ZeroResultSpend, 'last_30'));
        $this->assertNotSame([], $this->facts(MetaAdsRecommendationType::ZeroResultSpend, 'previous_month'));
    }

    // ---- pacing ---------------------------------------------------------------------------------

    public function test_pacing_over_and_under_with_enough_days(): void
    {
        $this->pinMetaClock('2026-10-10 12:00:00');
        $campaign = $this->seedMetaCampaign($this->account, 'Pace');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-09', 10_000_000);

        // 9 days, spent 90. Target 200 => expected 58.06; 90 > 66.8 => ahead.
        $this->account->update(['monthly_budget_target_micros' => 200_000_000]);
        $over = $this->facts(MetaAdsRecommendationType::PacingOver);
        $this->assertCount(1, $over);
        $this->assertSame('account', $over[0]->subjectType);
        $this->assertNull($over[0]->subjectUid);
        $this->assertSame(90_000_000, $over[0]->evidence['spend_micros']);
        $this->assertSame(200_000_000, $over[0]->evidence['monthly_target_micros']);
        $this->assertSame(9, $over[0]->evidence['days_with_data']);
        $this->assertSame('this_month', $over[0]->evidence['period_key']);
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::PacingUnder));

        // Target 400 => expected 116.1; 90 < 98.7 => behind.
        $this->account->update(['monthly_budget_target_micros' => 400_000_000]);
        $this->assertCount(1, $this->facts(MetaAdsRecommendationType::PacingUnder));
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::PacingOver));

        // Target 300 => expected 87.1; |90 - 87.1| within 15% => on pace, no fact.
        $this->account->update(['monthly_budget_target_micros' => 300_000_000]);
        $this->assertSame([], array_filter($this->facts(), fn ($f) => in_array($f->type, [MetaAdsRecommendationType::PacingOver, MetaAdsRecommendationType::PacingUnder], true)));
    }

    public function test_pacing_needs_a_target_and_at_least_min_days(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Pace');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-01', '2026-10-03', 50_000_000); // 3 days only

        $this->account->update(['monthly_budget_target_micros' => 10_000_000]);
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::PacingOver)); // 3 < 7 days: insufficient

        $this->pinMetaClock('2026-10-10 12:00:00');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $campaign->id, '2026-10-04', '2026-10-09', 50_000_000);
        $this->account->update(['monthly_budget_target_micros' => null]);
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::PacingOver)); // no target
    }

    // ---- high_frequency_weak_results -----------------------------------------------------------

    /** Seeds an ad set with the two 7-day windows (last: 09-28..10-04, previous: 09-21..09-27). */
    private function fatigueAdSet(string $frequency, int $prevSpend, int $prevResults, int $lastSpend, int $lastResults, array $overrides = []): \App\Models\MetaAdsAdSet
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Parent ' . uniqid());
        $adSet = $this->seedMetaAdSet($campaign, 'Ad set ' . uniqid(), array_merge(['frequency_7d' => $frequency, 'reach_7d' => 12000], $overrides));
        $this->seedWindow($adSet, '2026-09-21', '2026-09-27', $prevSpend, $prevResults);
        $this->seedWindow($adSet, '2026-09-28', '2026-10-04', $lastSpend, $lastResults);

        return $adSet;
    }

    /** Spreads total spend / results over 7 days: the remainder lands on day one. */
    private function seedWindow(\App\Models\MetaAdsAdSet $adSet, string $from, string $to, int $spend, int $results): void
    {
        $day = \Carbon\CarbonImmutable::parse($from);
        $i = 0;

        for (; $day->lessThanOrEqualTo(\Carbon\CarbonImmutable::parse($to)); $day = $day->addDay(), $i++) {
            $share = intdiv($spend, 7) + ($i === 0 ? $spend % 7 : 0);
            $this->seedMetaInsight($this->account, MetaAdsLevel::AdSet, $adSet->id, $day->format('Y-m-d'), $share);
        }

        if ($results > 0) {
            $this->seedMetaResult($this->account, MetaAdsLevel::AdSet, $adSet->id, $from, $results);
        }
    }

    public function test_fatigue_fires_when_cost_per_result_worsened_at_least_the_factor(): void
    {
        // previous: 70 / 7 = 10.00; last: 91 / 7 = 13.00 == exactly 1.3 x => fires (edge inclusive)
        $adSet = $this->fatigueAdSet('3.0000', 70_000_000, 7, 91_000_000, 7);

        $facts = $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults);

        $this->assertCount(1, $facts);
        $e = $facts[0]->evidence;
        $this->assertSame('ad_set', $facts[0]->subjectType);
        $this->assertSame($adSet->uid, $facts[0]->subjectUid);
        $this->assertSame('cost_per_result_worsened', $e['variant']);
        $this->assertSame(3.0, $e['frequency_7d']);
        $this->assertSame(13_000_000, $e['last_cost_per_result_micros']);
        $this->assertSame(10_000_000, $e['previous_cost_per_result_micros']);
        $this->assertSame('last_7', $e['period_key']);
        $this->assertSame('2026-09-28', $e['period_from']);
        $this->assertSame('2026-10-04', $e['period_to']);
        $this->assertSame('2026-09-21', $e['previous_period_from']);
        $this->assertSame(91_000_000, $facts[0]->rankSpendMicros);
        $this->assertSame(['key' => 'review_ad_set', 'target_uid' => $adSet->uid], $facts[0]->suggestedAction);
    }

    public function test_fatigue_does_not_fire_just_below_the_worsening_factor(): void
    {
        $this->fatigueAdSet('3.0000', 70_000_000, 7, 90_999_999, 7); // 12.99999x < 13.0

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));
    }

    public function test_fatigue_frequency_threshold_edge(): void
    {
        $this->fatigueAdSet('2.9999', 70_000_000, 7, 140_000_000, 7);

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));

        $this->fatigueAdSet('3.0000', 70_000_000, 7, 140_000_000, 7);
        $this->assertCount(1, $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));
    }

    public function test_fatigue_results_stopped_variant(): void
    {
        $adSet = $this->fatigueAdSet('4.2000', 70_000_000, 7, 30_000_000, 0);

        $facts = $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults);

        $this->assertCount(1, $facts);
        $this->assertSame($adSet->uid, $facts[0]->subjectUid);
        $this->assertSame('results_stopped', $facts[0]->evidence['variant']);
        $this->assertNull($facts[0]->evidence['last_cost_per_result_micros']);
        $this->assertSame('0', $facts[0]->evidence['last_results']);
    }

    public function test_fatigue_insufficient_evidence_produces_nothing(): void
    {
        $this->fatigueAdSet('4.0000', 70_000_000, 2, 140_000_000, 3);   // baseline below fatigue_min_results
        $this->fatigueAdSet('4.0000', 70_000_000, 7, 140_000_000, 2);   // last window 1..min-1 results: neither variant
        $this->fatigueAdSet('4.0000', 70_000_000, 7, 19_999_999, 0);    // spend below fatigue_min_spend
        $this->fatigueAdSet('4.0000', 0, 7, 140_000_000, 3);            // no baseline spend
        $this->fatigueAdSet('4.0000', 70_000_000, 7, 140_000_000, 3, ['status' => 'PAUSED']);
        $this->fatigueAdSet('4.0000', 70_000_000, 7, 140_000_000, 3, ['frequency_7d' => null]);

        $this->assertSame([], $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));
    }

    public function test_fatigue_needs_a_chosen_result_type(): void
    {
        $this->fatigueAdSet('4.0000', 70_000_000, 7, 140_000_000, 7);
        $this->assertCount(1, $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));

        $this->account->update(['result_action_type' => null]);
        $this->assertSame([], $this->facts(MetaAdsRecommendationType::HighFrequencyWeakResults));
    }

    // ---- delivery_issue --------------------------------------------------------------------------

    public function test_delivery_issue_only_for_active_entities_with_the_three_provider_statuses(): void
    {
        $campaign = $this->seedMetaCampaign($this->account, 'Issue campaign', ['effective_status' => 'WITH_ISSUES']);
        $adSet = $this->seedMetaAdSet($campaign, 'Issue set', ['effective_status' => 'PENDING_BILLING_INFO']);
        $ad = $this->seedMetaAd($adSet, 'Issue ad', ['effective_status' => 'DISAPPROVED']);
        $this->seedMetaInsight($this->account, MetaAdsLevel::Ad, $ad->id, '2026-10-02', 7_000_000);

        // Not facts: paused with issues, delivery states that are not the three, normal delivery.
        $this->seedMetaCampaign($this->account, 'Paused issue', ['status' => 'PAUSED', 'effective_status' => 'WITH_ISSUES']);
        $this->seedMetaCampaign($this->account, 'Review', ['effective_status' => 'PENDING_REVIEW']);
        $this->seedMetaCampaign($this->account, 'Parent paused', ['effective_status' => 'CAMPAIGN_PAUSED']);
        $this->seedMetaCampaign($this->account, 'Fine', ['effective_status' => 'ACTIVE']);
        $this->seedMetaCampaign($this->account, 'Unknown', ['effective_status' => null]);

        $facts = $this->facts(MetaAdsRecommendationType::DeliveryIssue);

        $this->assertCount(3, $facts);
        $byType = [];
        foreach ($facts as $fact) {
            $byType[$fact->subjectType] = $fact;
        }
        $this->assertSame('WITH_ISSUES', $byType['campaign']->evidence['effective_status']);
        $this->assertSame('PENDING_BILLING_INFO', $byType['ad_set']->evidence['effective_status']);
        $this->assertSame('DISAPPROVED', $byType['ad']->evidence['effective_status']);
        $this->assertSame($ad->uid, $byType['ad']->subjectUid);
        $this->assertSame(7_000_000, $byType['ad']->evidence['spend_micros']);
        $this->assertNull($byType['campaign']->evidence['spend_micros']); // no rows: null, not 0
        $this->assertSame(['key' => 'review_ad', 'target_uid' => $ad->uid], $byType['ad']->suggestedAction);
        $this->assertSame('rule:active_with_provider_reported_delivery_issue', $byType['ad']->factualBasis);
    }

    public function test_delivery_issue_works_without_a_result_type(): void
    {
        $this->seedMetaCampaign($this->account, 'Issue campaign', ['effective_status' => 'WITH_ISSUES']);
        $this->account->update(['result_action_type' => null]);

        $this->assertCount(1, $this->facts(MetaAdsRecommendationType::DeliveryIssue));
    }

    // ---- order, determinism, shape, currency -------------------------------------------------------

    public function test_ordering_is_spend_desc_then_type_then_uid_and_deterministic(): void
    {
        $this->campaignWith('Has results', 30_000_000, 3);                // strong
        $this->campaignWith('Zero big', 90_000_000, null);                // zero_result_spend 90
        $this->campaignWith('Costly', 40_000_000, 2);                     // cpr above target 40
        $this->seedMetaCampaign($this->account, 'Issue', ['effective_status' => 'WITH_ISSUES']); // delivery 0

        $first = $this->facts();
        $second = $this->facts();

        $this->assertEquals(array_map(fn ($f) => $f->toArray(), $first), array_map(fn ($f) => $f->toArray(), $second));
        $this->assertSame([90_000_000, 40_000_000, 30_000_000, 0], array_map(fn ($f) => $f->rankSpendMicros, $first));
        $this->assertSame(
            ['zero_result_spend', 'cost_per_result_above_target', 'strong_performer', 'delivery_issue'],
            array_map(fn ($f) => $f->type->value, $first),
        );
    }

    public function test_fact_shape_has_provider_meta_and_no_provider_ids(): void
    {
        $campaign = $this->campaignWith('Zero', 80_000_000, '0');

        $fact = $this->facts(MetaAdsRecommendationType::ZeroResultSpend)[0];
        $array = $fact->toArray();

        $this->assertSame('meta', $array['provider']);
        $this->assertSame(['type', 'provider', 'subject', 'evidence', 'suggested_action', 'factual_basis', 'currency_code'], array_keys($array));
        $this->assertSame(['type' => 'campaign', 'uid' => $campaign->uid, 'name' => 'Zero'], $array['subject']);
        $this->assertStringNotContainsString($campaign->external_campaign_id, json_encode($array));
        $this->assertStringNotContainsString($this->account->ad_account_id, json_encode($array));
        $this->assertSame('USD', $array['currency_code']);
    }

    public function test_provider_aware_identity(): void
    {
        $campaign = $this->campaignWith('Zero', 80_000_000, '0');
        $this->seedMetaCampaign($this->account, 'Issue', ['effective_status' => 'WITH_ISSUES']);

        $ids = app(MetaAdsRecommendationFactReader::class)->providerFactIds($this->account->fresh());

        $this->assertContains([
            'provider' => 'meta', 'type' => 'zero_result_spend', 'subject_type' => 'campaign',
            'subject_uid' => $campaign->uid, 'period_key' => 'last_30',
        ], $ids);
        $this->assertCount(2, $ids);
        foreach ($ids as $id) {
            $this->assertSame(['provider', 'type', 'subject_type', 'subject_uid', 'period_key'], array_keys($id));
            $this->assertSame('meta', $id['provider']);
        }
    }

    public function test_currency_is_kept_and_spend_thresholds_are_in_account_currency_micros(): void
    {
        $this->account->update(['currency_code' => 'JPY']);
        $this->campaignWith('Has results', 30_000_000, 3);
        $this->campaignWith('Zero', 50_000_000, null);

        $facts = $this->facts(MetaAdsRecommendationType::ZeroResultSpend);

        $this->assertCount(1, $facts);
        $this->assertSame('JPY', $facts[0]->currencyCode);
        $this->assertSame('JPY', $facts[0]->toArray()['currency_code']);
        $this->assertSame('JPY', $this->facts()[0]->currencyCode);
    }

    public function test_no_data_at_all_is_no_facts(): void
    {
        $this->assertSame([], $this->facts());
        $this->seedMetaCampaign($this->account, 'Empty');
        $this->assertSame([], $this->facts());
    }

    // ---- presenter --------------------------------------------------------------------------------

    public function test_presenter_wording_is_calm_deterministic_and_has_a_link_target(): void
    {
        $this->campaignWith('Has results', 30_000_000, 3);
        $this->campaignWith('Zero', 80_000_000, null, '2026-10-02');
        $this->campaignWith('Costly', 40_000_000, 2);
        $this->fatigueAdSet('3.5000', 70_000_000, 7, 140_000_000, 7);
        $this->fatigueAdSet('3.5000', 70_000_000, 7, 30_000_000, 0);
        $this->seedMetaCampaign($this->account, 'Issue', ['effective_status' => 'DISAPPROVED']);
        $this->pinMetaClock('2026-10-04 12:00:00');
        $this->account->update(['monthly_budget_target_micros' => 1_000_000]);

        $presenter = app(MetaAdsRecommendationPresenter::class);
        $facts = $this->facts();
        $types = array_unique(array_map(fn ($f) => $f->type->value, $facts));
        $this->assertContains('zero_result_spend', $types);
        $this->assertContains('cost_per_result_above_target', $types);
        $this->assertContains('high_frequency_weak_results', $types);
        $this->assertContains('delivery_issue', $types);
        $this->assertContains('strong_performer', $types);

        foreach ($facts as $fact) {
            $view = $presenter->present($fact);
            $text = strtolower($view['title'] . ' ' . implode(' ', $view['evidence_lines']) . ' ' . $view['basis_line'] . ' ' . $view['action_label']);

            $this->assertNotSame('', $view['title']);
            $this->assertNotEmpty($view['evidence_lines']);
            $this->assertStringStartsWith('Based on your cached Meta Ads data for ', $view['basis_line']);
            $this->assertStringEndsWith('; deterministic rule.', $view['basis_line']);
            $this->assertContains($view['link_target'], ['campaign', 'ad_set', 'ad', 'budget']);
            foreach (['wasted', 'waste', 'search term', 'keyword', 'because', 'caused', 'due to', 'fatigue', 'urgent', 'critical'] as $banned) {
                $this->assertStringNotContainsString($banned, $text, $fact->type->value . ' / ' . $banned);
            }
            $this->assertStringNotContainsString('{', $text);
        }

        $zero = $presenter->present($this->facts(MetaAdsRecommendationType::ZeroResultSpend)[0]);
        $this->assertSame('Zero has spend but no results yet', $zero['title']);
        $this->assertStringContainsString('Leads (on-Facebook forms)', implode(' ', $zero['evidence_lines']));
        $this->assertStringContainsString('last 30 days', $zero['basis_line']);
        $this->assertSame('campaign', $zero['link_target']);
    }
}
