<?php

namespace Tests\Unit\Growth;

use App\Enums\Growth\GrowthRuleOutcomeStatus as S;
use App\Library\Growth\GrowthAdvisorContext;
use App\Library\Growth\GrowthEvaluationService;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SEO V1 final (A5, A9) — the SEO rules over synthetic facts, no database:
 *  - keyword coverage is ONE Business-wide finding (v2), never one per Location;
 *  - the two rank rules judge STORED observations only and never double-report
 *    a keyword another rule already explains;
 *  - an unavailable / not-entitled rank domain is Not applicable, never "all clear".
 */
class GrowthSeoRankRulesTest extends TestCase
{
    private const DROP = 'seo.meaningful_rank_drop:v1';

    private const NEAR_TOP = 'seo.rank_just_outside_top_10:v1';

    private const COVERAGE = 'seo.keywords_not_covered:v2';

    /** @param  array<string, GrowthFactSet>  $sets */
    private function snapshot(array $sets): GrowthFactSnapshot
    {
        return new GrowthFactSnapshot(new Business(), CarbonImmutable::parse('2026-10-04 12:00:00'), new GrowthThresholds(), $sets, [1 => 'Main', 2 => 'Second']);
    }

    private function outcome(string $ruleKey, GrowthFactSnapshot $facts)
    {
        return app(GrowthEvaluationService::class)->evaluateRules($facts)[$ruleKey];
    }

    private function seo(array $data = []): GrowthFactSet
    {
        return GrowthFactSet::available('seo', $data + [
            'keyword_count' => 6, 'coverage_known' => true, 'covered_count' => 4,
            'not_covered' => [], 'not_covered_total' => 0, 'not_covered_examples' => [], 'not_covered_ids' => [],
            'audit_ran' => true, 'audit_findings' => ['critical' => 0, 'warning' => 0, 'rules' => []],
        ]);
    }

    private function rank(array $data = []): GrowthFactSet
    {
        return GrowthFactSet::available('rank', $data + [
            'tracked_count' => 4, 'judged_count' => 4, 'comparable_count' => 4,
            'improved_count' => 0, 'top10_count' => 0, 'drops' => [], 'near_top' => [],
        ]);
    }

    private function row(int $id, int $location, string $phrase, array $extra = []): array
    {
        return ['id' => $id, 'location' => $location, 'phrase' => $phrase] + $extra;
    }

    // ── coverage v2 ──────────────────────────────────────────────────────

    public function test_a_site_wide_coverage_gap_is_one_business_wide_finding_however_many_locations_repeat_it(): void
    {
        // Three Locations each carry the same uncovered phrase: v1 raised three findings for one problem.
        $facts = $this->snapshot(['seo' => $this->seo([
            'not_covered' => [1 => ['count' => 1, 'phrases' => ['neon sign hire']], 2 => ['count' => 1, 'phrases' => ['neon sign hire']], 3 => ['count' => 1, 'phrases' => ['neon sign hire']]],
            'not_covered_total' => 1,
            'not_covered_examples' => [],
        ])]);

        $outcome = $this->outcome(self::COVERAGE, $facts);

        $this->assertSame(S::Finding, $outcome->status);
        $this->assertCount(1, $outcome->findings);
        $this->assertNull($outcome->findings[0]->locationId);
        $this->assertSame(1, $outcome->findings[0]->evidence['count']);
        $this->assertSame([], $outcome->findings[0]->evidence['phrases'], 'A Location\'s own keyword text never rides a finding every member can see.');
    }

    public function test_business_wide_example_phrases_are_carried_and_markup_is_dropped(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(['not_covered_total' => 3, 'not_covered_examples' => ['karaoke', '<b>bold</b>', 'neon']])]);

        $evidence = $this->outcome(self::COVERAGE, $facts)->findings[0]->evidence;

        $this->assertSame(3, $evidence['count'], 'The count still includes the phrase left out of the examples.');
        $this->assertSame(['karaoke', 'neon'], $evidence['phrases']);
    }

    public function test_coverage_is_scoped_to_the_business_not_a_location(): void
    {
        $this->assertSame('business', GrowthRuleRegistry::find(self::COVERAGE)->definition()->scope);
        $this->assertNull(GrowthRuleRegistry::find('seo.keywords_not_covered:v1'), 'The per-Location v1 is gone: old Opportunities simply stop being re-confirmed.');
    }

    // ── rank drop ────────────────────────────────────────────────────────

    public function test_a_meaningful_drop_is_a_finding_per_location_with_the_phrases_of_that_location(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['drops' => [
            $this->row(10, 1, 'photo booth rental', ['kind' => 'down', 'amount' => 8]),
            $this->row(11, 1, 'wedding booth', ['kind' => 'dropped', 'amount' => null]),
            $this->row(12, 0, 'party booth', ['kind' => 'down', 'amount' => 6]),
        ]])]);

        $outcome = $this->outcome(self::DROP, $facts);

        $this->assertSame(S::Finding, $outcome->status);
        $byLocation = [];
        foreach ($outcome->findings as $finding) {
            $byLocation[$finding->locationId ?? 0] = $finding;
        }

        $this->assertEqualsCanonicalizing([0, 1], array_keys($byLocation));
        $this->assertSame(2, $byLocation[1]->evidence['count']);
        $this->assertEqualsCanonicalizing(['photo booth rental', 'wedding booth'], $byLocation[1]->evidence['phrases']);
        $this->assertSame(['party booth'], $byLocation[0]->evidence['phrases'], 'Business-wide keywords stay Business-wide.');
    }

    public function test_no_drop_is_passing_and_says_what_improved(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['improved_count' => 2])]);

        $outcome = $this->outcome(self::DROP, $facts);

        $this->assertSame(S::Passing, $outcome->status);
        $this->assertSame('2 tracked keywords moved up in Google since the previous check.', GrowthRuleRegistry::find(self::DROP)->positiveStatement($outcome->positive));
        $this->assertNull(GrowthRuleRegistry::find(self::DROP)->positiveStatement(['improved' => 0]), 'Nothing improved: nothing to celebrate.');
    }

    public function test_a_drop_cannot_be_judged_without_an_earlier_result_to_compare(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['comparable_count' => 0, 'judged_count' => 3])]);

        $this->assertSame(S::Insufficient, $this->outcome(self::DROP, $facts)->status, 'One check is not a trend.');
    }

    public function test_no_rank_data_at_all_is_insufficient_not_all_clear(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['tracked_count' => 0, 'judged_count' => 0, 'comparable_count' => 0])]);

        $this->assertSame(S::Insufficient, $this->outcome(self::DROP, $facts)->status);
        $this->assertSame(S::Insufficient, $this->outcome(self::NEAR_TOP, $facts)->status);
    }

    public function test_an_unavailable_or_unentitled_rank_domain_is_not_applicable_never_zero(): void
    {
        foreach ([GrowthFactSet::withStatus('rank', \App\Enums\Growth\GrowthFactStatus::Unavailable), GrowthFactSet::withStatus('rank', \App\Enums\Growth\GrowthFactStatus::NotEntitled)] as $set) {
            $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $set]);

            $this->assertSame(S::NotApplicable, $this->outcome(self::DROP, $facts)->status);
            $this->assertSame(S::NotApplicable, $this->outcome(self::NEAR_TOP, $facts)->status);
        }
    }

    // ── just outside the top 10 ──────────────────────────────────────────

    public function test_positions_11_to_20_are_a_finding_and_a_clean_result_celebrates_page_one(): void
    {
        $hit = $this->outcome(self::NEAR_TOP, $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['near_top' => [
            $this->row(20, 1, 'photo booth rental', ['position' => 14]),
        ]])]));

        $this->assertSame(S::Finding, $hit->status);
        $this->assertSame(1, $hit->findings[0]->locationId);
        $this->assertSame(['photo booth rental'], $hit->findings[0]->evidence['phrases']);

        $clean = $this->outcome(self::NEAR_TOP, $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank(['top10_count' => 3])]));
        $this->assertSame(S::Passing, $clean->status);
        $this->assertSame('3 tracked keywords are on the first page of Google.', GrowthRuleRegistry::find(self::NEAR_TOP)->positiveStatement($clean->positive));
    }

    // ── one root problem, one recommendation ─────────────────────────────

    public function test_a_keyword_the_website_never_mentions_is_reported_once_by_coverage_not_again_by_rank(): void
    {
        $facts = $this->snapshot([
            'seo' => $this->seo(['not_covered_total' => 1, 'not_covered_ids' => [10], 'not_covered_examples' => ['photo booth rental']]),
            'rank' => $this->rank([
                'drops' => [$this->row(10, 0, 'photo booth rental', ['kind' => 'down', 'amount' => 9])],
                'near_top' => [$this->row(10, 0, 'photo booth rental', ['position' => 13])],
            ]),
        ]);

        $this->assertSame(S::Finding, $this->outcome(self::COVERAGE, $facts)->status);
        $this->assertSame(S::Passing, $this->outcome(self::DROP, $facts)->status, 'Already explained by the coverage gap.');
        $this->assertSame(S::Passing, $this->outcome(self::NEAR_TOP, $facts)->status);
    }

    public function test_a_dropped_keyword_is_not_also_reported_as_just_outside_the_top_ten(): void
    {
        $facts = $this->snapshot(['seo' => $this->seo(), 'rank' => $this->rank([
            'drops' => [$this->row(10, 0, 'wedding booth', ['kind' => 'down', 'amount' => 6])],
            'near_top' => [
                $this->row(10, 0, 'wedding booth', ['position' => 14]),
                $this->row(11, 0, 'party booth', ['position' => 17]),
            ],
        ])]);

        $this->assertSame(S::Finding, $this->outcome(self::DROP, $facts)->status);

        $near = $this->outcome(self::NEAR_TOP, $facts);
        $this->assertSame(S::Finding, $near->status);
        $this->assertSame(['party booth'], $near->findings[0]->evidence['phrases']);
        $this->assertSame(1, $near->findings[0]->evidence['count']);
    }

    public function test_rank_rules_still_work_when_the_seo_domain_is_unavailable(): void
    {
        $facts = $this->snapshot(['rank' => $this->rank(['drops' => [$this->row(10, 0, 'wedding booth', ['kind' => 'dropped', 'amount' => null])]])]);

        $this->assertSame(S::Finding, $this->outcome(self::DROP, $facts)->status, 'No coverage facts means nothing to de-duplicate against, not no finding.');
    }

    // ── closed registry, copy, thresholds, provider policy ───────────────

    public function test_the_rank_rules_are_registered_with_plain_copy_and_a_read_only_action(): void
    {
        foreach ([self::DROP, self::NEAR_TOP] as $key) {
            $definition = GrowthRuleRegistry::find($key)->definition();

            $this->assertSame('rank', $definition->domain);
            $this->assertSame('seo', $definition->worker->value);
            $this->assertSame('seo.keywords', $definition->target);
            $this->assertSame('read_only', $definition->safetyClass->value);
            $this->assertNotEmpty($definition->why);
        }

        $this->assertSame('3 tracked keywords have dropped in Google since the previous check.', GrowthRuleRegistry::find(self::DROP)->headline(['count' => 3]));
        $this->assertSame('1 tracked keyword is just outside the first page of Google.', GrowthRuleRegistry::find(self::NEAR_TOP)->headline(['count' => 1]));
    }

    public function test_rank_facts_never_reach_the_ai(): void
    {
        $this->assertContains('rank', GrowthAdvisorContext::AI_FORBIDDEN_DOMAINS);
        $this->assertContains('search_console', GrowthAdvisorContext::AI_FORBIDDEN_DOMAINS);
    }

    public function test_the_drop_threshold_is_configured_and_clamped(): void
    {
        $this->assertSame(5, (new GrowthThresholds())->get('rank_drop_positions'));

        config(['growth.thresholds.rank_drop_positions' => 0]);
        $this->assertSame(1, (new GrowthThresholds())->get('rank_drop_positions'));

        config(['growth.thresholds.rank_drop_positions' => 9999]);
        $this->assertSame(50, (new GrowthThresholds())->get('rank_drop_positions'));

        config(['growth.thresholds.rank_drop_positions' => 'abc']);
        $this->assertSame(5, (new GrowthThresholds())->get('rank_drop_positions'));
    }
}
