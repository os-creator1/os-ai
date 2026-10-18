<?php

namespace Tests\Feature\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFact;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationPresenter;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationType;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\GoogleAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\TestCase;

/**
 * Contract §12 — recommendation facts: the fire / no-fire matrix, including
 * every "insufficient evidence => no fact" case, ordering and presenter copy.
 * Defaults: zero-conv campaign min spend 50M, cpl_over_factor 1.25,
 * strong_min_conversions 3, waste 20M / 5 clicks, pacing min 7 days / 15%.
 */
class GoogleAdsRecommendationFactReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGoogleAdsReportingData;

    private GoogleAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinAdsClock();
        [, $business] = $this->adsTenant();
        $this->account = $this->adsAccountFor($business);
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    /** @return array<int, GoogleAdsRecommendationFact> */
    private function facts(?string $period = null): array
    {
        return app(GoogleAdsRecommendationFactReader::class)->facts($this->account->fresh(), $period === null ? null : GoogleAdsPeriod::resolve($period, $this->account));
    }

    /** @return array<int, string> */
    private function types(array $facts): array
    {
        return array_map(fn (GoogleAdsRecommendationFact $f) => $f->type->value, $facts);
    }

    private function campaignWith(string $id, string $name, int $cost, ?string $conversions = null, array $o = []): \App\Models\GoogleAdsCampaign
    {
        $campaign = $this->seedCampaign($this->account, $id, $name, $o);
        $this->seedMetric($this->account, GoogleAdsMetricLevel::Campaign, $id, '2026-10-02', $cost, 10, $conversions);

        return $campaign;
    }

    public function test_no_data_means_no_facts(): void
    {
        $this->assertSame([], $this->facts());
    }

    public function test_zero_conversion_campaign_fires_at_threshold_with_conversion_data_only(): void
    {
        $fires = $this->campaignWith('1', 'Zero A', 50_000_000, '0');
        $this->campaignWith('2', 'Under threshold', 49_999_999, '0');
        $this->campaignWith('3', 'No conversion data', 90_000_000, null);
        $this->campaignWith('4', 'Paused zero', 90_000_000, '0', ['status' => GoogleAdsEntityStatus::Paused]);
        $this->campaignWith('5', 'Converts', 90_000_000, '2');

        $facts = $this->facts();

        $this->assertSame(['zero_conversion_campaign'], $this->types($facts));
        $fact = $facts[0];
        $this->assertSame($fires->uid, $fact->subjectUid);
        $this->assertSame('Zero A', $fact->subjectName);
        $this->assertSame('campaign', $fact->subjectType);
        $this->assertSame('rule:zero_conversions_min_spend', $fact->factualBasis);
        $this->assertSame(['key' => 'review_campaign', 'target_uid' => $fires->uid], $fact->suggestedAction);
        $this->assertSame(50_000_000, $fact->evidence['spend_micros']);
        $this->assertSame('0', $fact->evidence['conversions']);
        $this->assertSame(10, $fact->evidence['clicks']);
        $this->assertSame('last_30', $fact->evidence['period_key']);
        $this->assertSame('2026-09-05', $fact->evidence['period_from']);
        $this->assertSame('2026-10-04', $fact->evidence['period_to']);
        $this->assertSame('USD', $fact->currencyCode);
    }

    public function test_cpl_rules_need_a_target(): void
    {
        $this->campaignWith('1', 'Costly', 130_000_000, '10'); // CPL 13M
        $this->campaignWith('2', 'Strong', 90_000_000, '10');  // CPL 9M

        $this->assertSame([], $this->facts(), 'no target => no CPL fact');
    }

    public function test_cpl_above_target_and_strong_campaign_fire_with_a_target(): void
    {
        $this->account->forceFill(['target_cpl_micros' => 10_000_000])->save();
        $costly = $this->campaignWith('1', 'Costly', 130_000_000, '10');      // CPL 13M > 12.5M
        $edge = $this->campaignWith('2', 'Edge', 125_000_000, '10');          // CPL 12.5M: not strictly above
        $strong = $this->campaignWith('3', 'Strong', 90_000_000, '10');       // CPL 9M, 10 conv
        $this->campaignWith('4', 'Strong few', 27_000_000, '3');              // 3 conv: fires (>= 3)
        $this->campaignWith('5', 'Too few', 18_000_000, '2');                 // CPL 9M, 2 conv: not strong
        $this->campaignWith('6', 'On target', 100_000_000, '10');             // CPL == target: strong
        unset($edge);

        $facts = $this->facts();
        $byName = [];
        foreach ($facts as $fact) {
            $byName[$fact->subjectName] = $fact;
        }

        $this->assertSame(GoogleAdsRecommendationType::CplAboveTarget, $byName['Costly']->type);
        $this->assertSame('rule:cpl_over_target_factor', $byName['Costly']->factualBasis);
        $this->assertSame(13_000_000, $byName['Costly']->evidence['cpl_micros']);
        $this->assertSame(10_000_000, $byName['Costly']->evidence['target_cpl_micros']);
        $this->assertSame('10', $byName['Costly']->evidence['conversions']);
        $this->assertSame($costly->uid, $byName['Costly']->subjectUid);

        $this->assertArrayNotHasKey('Edge', $byName);
        $this->assertSame(GoogleAdsRecommendationType::StrongCampaign, $byName['Strong']->type);
        $this->assertSame($strong->uid, $byName['Strong']->subjectUid);
        $this->assertSame(GoogleAdsRecommendationType::StrongCampaign, $byName['Strong few']->type);
        $this->assertArrayNotHasKey('Too few', $byName);
        $this->assertSame(GoogleAdsRecommendationType::StrongCampaign, $byName['On target']->type);
    }

    public function test_a_campaign_with_conversions_but_no_cpl_inputs_never_fires(): void
    {
        $this->account->forceFill(['target_cpl_micros' => 10_000_000])->save();
        $this->campaignWith('1', 'No conv data', 500_000_000, null);

        $this->assertSame([], $this->facts());
    }

    public function test_wasted_search_terms_fact_excludes_already_handled_terms(): void
    {
        $campaign = $this->seedCampaign($this->account, '1', 'Alpha');
        $group = $this->seedAdGroup($campaign, '9', 'General');
        $this->seedSearchTerm($campaign, $group, 'waste one', '2026-10-02', 25_000_000, 3, '0');
        $this->seedSearchTerm($campaign, $group, 'waste two', '2026-10-02', 5_000_000, 6, '0');
        $this->seedSearchTerm($campaign, $group, 'handled', '2026-10-02', 80_000_000, 6, '0');
        $this->seedSearchTerm($campaign, $group, 'converting', '2026-10-02', 80_000_000, 6, '2');
        $this->seedKeyword($campaign, null, '1~1', 'handled', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);

        $facts = $this->facts();

        $this->assertSame(['wasted_search_terms'], $this->types($facts));
        $e = $facts[0]->evidence;
        $this->assertSame(2, $e['term_count']);
        $this->assertSame(30_000_000, $e['wasted_spend_micros']);
        $this->assertSame(9, $e['wasted_clicks']);
        $this->assertSame('waste one', $e['top_term']);
        $this->assertSame(25_000_000, $e['top_term_spend_micros']);
        $this->assertSame(1, $e['already_excluded_count']);
        $this->assertSame('rule:wasted_search_terms_min_spend', $facts[0]->factualBasis);
        $this->assertSame(['key' => 'review_search_terms', 'target_uid' => null], $facts[0]->suggestedAction);
        $this->assertSame('account', $facts[0]->subjectType);
        $this->assertNull($facts[0]->subjectUid);
    }

    public function test_no_waste_fact_when_nothing_is_actionable(): void
    {
        $campaign = $this->seedCampaign($this->account, '1', 'Alpha');
        $group = $this->seedAdGroup($campaign, '9', 'General');
        $this->seedSearchTerm($campaign, $group, 'small', '2026-10-02', 2_000_000, 1, '0');
        $this->seedSearchTerm($campaign, $group, 'no data', '2026-10-02', 90_000_000, 20, null);

        $this->assertSame([], $this->facts());
    }

    public function test_pacing_facts_need_enough_days_and_a_target(): void
    {
        $this->pinAdsClock('2026-10-12 12:00:00');
        $this->seedCampaign($this->account, '1', 'Alpha');
        $this->account->forceFill(['monthly_budget_target_micros' => 310_000_000])->save();

        // 3 days only: insufficient data => no fact even though wildly over.
        $this->seedCampaignDays($this->account, '1', '2026-10-09', '2026-10-11', 90_000_000, 10, '1');
        $this->assertSame([], $this->facts('last_7'));
    }

    public function test_pacing_over_and_under(): void
    {
        $this->pinAdsClock('2026-10-12 12:00:00');
        $this->seedCampaign($this->account, '1', 'Alpha');
        $this->account->forceFill(['monthly_budget_target_micros' => 310_000_000])->save();
        // Oct 1..11 at 20M/day = 220M against an expected 110M: ahead.
        $this->seedCampaignDays($this->account, '1', '2026-10-01', '2026-10-11', 20_000_000, 10, null);

        $facts = $this->facts();
        $this->assertSame(['pacing_over'], $this->types($facts));
        $e = $facts[0]->evidence;
        $this->assertSame(310_000_000, $e['monthly_target_micros']);
        $this->assertSame(220_000_000, $e['spend_micros']);
        $this->assertSame(11, $e['days_elapsed']);
        $this->assertSame(11, $e['days_with_data']);
        $this->assertSame(31, $e['days_in_month']);
        $this->assertSame(620_000_000, $e['projected_micros']);
        $this->assertFalse($e['projection_low_confidence']);
        $this->assertSame('rule:pacing_ahead_of_target', $facts[0]->factualBasis);
        $this->assertSame(['key' => 'review_budget', 'target_uid' => null], $facts[0]->suggestedAction);

        \App\Models\GoogleAdsDailyMetric::query()->where('google_ads_account_id', $this->account->id)->update(['cost_micros' => 2_000_000]);
        $under = $this->facts();
        $this->assertSame(['pacing_under'], $this->types($under));
        $this->assertSame('rule:pacing_behind_target', $under[0]->factualBasis);

        // On pace (expected 110M +/- 15%): no fact.
        \App\Models\GoogleAdsDailyMetric::query()->where('google_ads_account_id', $this->account->id)->update(['cost_micros' => 10_000_000]);
        $this->assertSame([], $this->facts());

        // No target: no pacing fact whatever the spend.
        $this->account->forceFill(['monthly_budget_target_micros' => null])->save();
        \App\Models\GoogleAdsDailyMetric::query()->where('google_ads_account_id', $this->account->id)->update(['cost_micros' => 90_000_000]);
        $this->assertSame([], $this->facts());
    }

    public function test_facts_are_ordered_by_spend_descending_and_deterministically(): void
    {
        $this->account->forceFill(['target_cpl_micros' => 10_000_000])->save();
        $this->campaignWith('1', 'Zero small', 60_000_000, '0');
        $this->campaignWith('2', 'Costly big', 200_000_000, '10');   // CPL 20M
        $this->campaignWith('3', 'Strong mid', 90_000_000, '10');    // CPL 9M
        $campaign = $this->seedCampaign($this->account, '9', 'Terms');
        $group = $this->seedAdGroup($campaign, '99', 'G');
        $this->seedSearchTerm($campaign, $group, 'waste one', '2026-10-02', 25_000_000, 3, '0');

        $first = $this->facts();
        $second = $this->facts();

        $this->assertSame(['cpl_above_target', 'strong_campaign', 'zero_conversion_campaign', 'wasted_search_terms'], $this->types($first));
        $this->assertSame(array_map(fn ($f) => $f->toArray(), $first), array_map(fn ($f) => $f->toArray(), $second));
    }

    public function test_facts_never_contain_external_ids_and_evidence_is_scalar(): void
    {
        $this->account->forceFill(['target_cpl_micros' => 10_000_000])->save();
        $this->campaignWith('1000000001', 'Costly', 130_000_000, '10');

        foreach ($this->facts() as $fact) {
            $this->assertStringNotContainsString('1000000001', json_encode($fact->toArray()));
            foreach ($fact->evidence as $key => $value) {
                $this->assertTrue($value === null || is_scalar($value), $key);
            }
        }
    }

    public function test_presenter_produces_calm_plain_english_in_the_account_currency(): void
    {
        $this->account->forceFill(['target_cpl_micros' => 10_000_000, 'currency_code' => 'GBP'])->save();
        $this->campaignWith('1', 'Costly', 130_000_000, '10');
        $this->campaignWith('2', 'Zero A', 60_000_000, '0');
        $campaign = $this->seedCampaign($this->account, '9', 'Terms');
        $group = $this->seedAdGroup($campaign, '99', 'G');
        $this->seedSearchTerm($campaign, $group, 'waste one', '2026-10-02', 25_000_000, 3, '0');
        $presenter = new GoogleAdsRecommendationPresenter();

        $byType = [];
        foreach ($this->facts() as $fact) {
            $byType[$fact->type->value] = $presenter->present($fact);
        }

        $waste = $byType['wasted_search_terms'];
        $this->assertSame('Potential wasted spend on search terms', $waste['title']);
        $this->assertSame('Review search terms', $waste['action_label']);
        $this->assertStringContainsString('GBP 25.00', $waste['evidence_lines'][0]);
        $this->assertStringContainsString('1 search term', $waste['evidence_lines'][0]);
        $this->assertStringContainsString('"waste one"', $waste['evidence_lines'][1]);

        $zero = $byType['zero_conversion_campaign'];
        $this->assertStringContainsString('Zero A', $zero['title']);
        $this->assertStringContainsString('GBP 60.00', $zero['evidence_lines'][0]);

        $over = $byType['cpl_above_target'];
        $this->assertStringContainsString('GBP 13.00', $over['evidence_lines'][0]);
        $this->assertStringContainsString('GBP 10.00', $over['evidence_lines'][0]);

        $alarm = '/\b(urgent|critical|alert|danger|disaster|emergency|warning|waste of money|losing money|bleeding)\b/i';
        foreach ($byType as $copy) {
            $this->assertDoesNotMatchRegularExpression($alarm, $copy['title'] . ' ' . implode(' ', $copy['evidence_lines']));
            $this->assertNotSame('', $copy['action_label']);
            $this->assertNotEmpty($copy['evidence_lines']);
        }
    }

    public function test_presenter_covers_every_fact_type(): void
    {
        $presenter = new GoogleAdsRecommendationPresenter();

        foreach (GoogleAdsRecommendationType::cases() as $type) {
            $fact = new GoogleAdsRecommendationFact(
                $type,
                'campaign',
                'uid',
                'Camp',
                [
                    'period_key' => 'last_30', 'period_from' => '2026-09-05', 'period_to' => '2026-10-04', 'spend_micros' => 100_000_000,
                    'term_count' => 2, 'wasted_spend_micros' => 100_000_000, 'wasted_clicks' => 4, 'top_term' => 't', 'top_term_spend_micros' => 50_000_000,
                    'top_term_clicks' => 2, 'already_excluded_count' => 0, 'clicks' => 5, 'impressions' => 50, 'conversions' => '3', 'cpl_micros' => 9_000_000,
                    'target_cpl_micros' => 10_000_000, 'monthly_target_micros' => 300_000_000, 'projected_micros' => 500_000_000,
                    'elapsed_proportion' => 0.35, 'spend_proportion' => 0.5,
                ],
                ['key' => 'k', 'target_uid' => null],
                'rule:x',
                100_000_000,
                'USD',
            );

            $copy = $presenter->present($fact);

            $this->assertNotSame('', $copy['title'], $type->value);
            $this->assertNotEmpty($copy['evidence_lines'], $type->value);
            $this->assertStringContainsString('USD ', implode(' ', $copy['evidence_lines']), $type->value);
        }
    }
}
