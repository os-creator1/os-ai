<?php

namespace Tests\Feature\Growth;

use App\Enums\Growth\GrowthFactStatus;
use App\Enums\Growth\GrowthRuleOutcomeStatus as S;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\GrowthRankFactReader;
use App\Models\SeoRankLocation;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * SEO V1 final (A5, A9) — what the Growth Center concludes about SEO from
 * REAL rows: keyword coverage (one site-wide finding, hidden pages and archived
 * Locations judged honestly) and the rank facts built from STORED observations.
 *
 * Growth never calls a provider: the rank reader reads the rows the rank module
 * already wrote, and no HTTP request is possible in these tests.
 */
class GrowthSeoRankFactsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;
    use CreatesRankObservations;

    private const COVERAGE = 'seo.keywords_not_covered:v2';

    private const DROP = 'seo.meaningful_rank_drop:v1';

    private const NEAR_TOP = 'seo.rank_just_outside_top_10:v1';

    private ?SeoRankLocation $rankLocation = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    private function facts()
    {
        return app(GrowthFactSnapshotBuilder::class)->build($this->business->fresh());
    }

    private function page(string $uid, string $body, bool $noindex = false, bool $home = false): array
    {
        return [
            'uid' => $uid, 'slug' => $home ? 'home' : $uid, 'is_home' => $home, 'title' => ucfirst($uid),
            'seo' => ['noindex' => $noindex],
            'sections' => [['type' => 'text', 'data' => ['heading' => ucfirst($uid), 'body' => $body]]],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $pages */
    private function publishSite(array $pages): void
    {
        $websiteId = DB::table('websites')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_id' => (string) Str::uuid(),
            'business_id' => $this->business->id, 'name' => 'Site', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revisionId = DB::table('website_revisions')->insertGetId([
            'uid' => (string) Str::uuid(), 'website_id' => $websiteId, 'version_number' => 1, 'schema_version' => 1,
            'created_by' => $this->business->customer_id, 'created_at' => now(),
            'snapshot' => json_encode(['website' => ['name' => 'Site'], 'pages' => $pages, 'assets' => []]),
        ]);
        DB::table('websites')->where('id', $websiteId)->update(['status' => 'published', 'published_revision_id' => $revisionId]);
    }

    private function keyword(string $phrase, ?int $locationId = null, string $state = 'active'): int
    {
        return (int) DB::table('seo_keywords')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'business_location_id' => $locationId,
            'phrase' => $phrase, 'phrase_normalized' => mb_strtolower($phrase), 'lifecycle_state' => $state, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function target(int $keywordId, string $state = 'tracking'): SeoRankTarget
    {
        $this->rankLocation ??= (function () {
            $location = new SeoRankLocation();
            $location->forceFill(['provider' => 'dataforseo', 'location_code' => 1016367, 'location_name' => 'Chicago,Illinois,United States', 'country_iso' => 'US', 'location_type' => 'City'])->save();

            return $location;
        })();

        $target = new SeoRankTarget();
        $target->forceFill([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'seo_keyword_id' => $keywordId,
            'provider' => 'dataforseo', 'seo_rank_location_id' => $this->rankLocation->id, 'search_location_code' => $this->rankLocation->location_code,
            'language_code' => 'en', 'device' => 'mobile', 'tracking_state' => $state, 'tracked_since' => now(),
        ])->save();

        return $target;
    }

    /** A tracked keyword with a previous and a current organic result. */
    private function tracked(string $phrase, ?int $previous, ?int $current, ?int $locationId = null, int $currentAgeDays = 1, ?string $currentStatus = null): int
    {
        $keywordId = $this->keyword($phrase, $locationId);
        $target = $this->target($keywordId);

        if ($previous !== null) {
            $this->obs($target, 'organic', $previous, now()->subDays($currentAgeDays + 3));
        }

        if ($current !== null || $currentStatus !== null) {
            $this->obs($target, 'organic', $current, now()->subDays($currentAgeDays), $currentStatus);
        }

        return $keywordId;
    }

    // ── coverage: one finding for one site-wide gap ──────────────────────

    public function test_the_same_uncovered_phrase_on_three_locations_is_one_business_wide_finding(): void
    {
        $this->publishSite([$this->page('home', 'We rent photo booths.', false, true)]);
        $second = $this->secondLocation();
        $third = $this->secondLocation('Third site');

        foreach ([$this->primaryLocation->id, $second->id, $third->id] as $locationId) {
            $this->keyword('neon sign hire', $locationId);
        }
        $this->keyword('karaoke machine hire');   // Business-wide
        $this->keyword('photo booths');            // covered

        $seo = $this->facts()->set('seo');

        $this->assertSame(2, $seo->get('not_covered_total'), 'Three Locations repeating one phrase plus one other: two distinct gaps.');
        $this->assertSame(['karaoke machine hire'], $seo->get('not_covered_examples'), 'Only Business-wide keyword text is carried.');
        $this->assertSame(1, $seo->get('covered_count'));
        $this->assertCount(4, $seo->get('not_covered_ids'));

        $outcome = $this->evaluateGrowth()['outcomes'][self::COVERAGE];
        $this->assertSame(S::Finding, $outcome->status);
        $this->assertCount(1, $outcome->findings);
        $this->assertNull($outcome->findings[0]->locationId);
        $this->assertSame(2, $outcome->findings[0]->evidence['count']);
        $this->assertStringNotContainsString('neon sign hire', json_encode($outcome->findings[0]->evidence), 'A Location\'s own keyword never rides the Business-wide finding.');

        $this->assertCount(1, $this->growthOpportunities(self::COVERAGE), 'One Opportunity for one root problem.');
    }

    public function test_a_keyword_on_an_archived_location_is_not_a_gap(): void
    {
        $this->publishSite([$this->page('home', 'We rent photo booths.', false, true)]);
        $closed = $this->secondLocation('Closed site');
        $this->keyword('closed site phrase', $closed->id);
        $this->keyword('open site phrase', $this->primaryLocation->id);
        $this->archiveLocation($closed);

        $seo = $this->facts()->set('seo');

        $this->assertSame(1, $seo->get('keyword_count'));
        $this->assertSame(1, $seo->get('not_covered_total'));
        $this->assertSame([], array_filter($seo->get('not_covered'), fn ($row, $key) => $key === $closed->id, ARRAY_FILTER_USE_BOTH));
    }

    public function test_a_phrase_only_on_a_hidden_page_is_neither_covered_nor_missing(): void
    {
        $this->publishSite([
            $this->page('home', 'We rent photo booths.', false, true),
            $this->page('private', 'Neon sign hire for parties.', true),
        ]);
        $this->keyword('neon sign hire');
        $this->keyword('photo booths');

        $seo = $this->facts()->set('seo');

        $this->assertSame(1, $seo->get('covered_count'));
        $this->assertSame(0, $seo->get('not_covered_total'), 'The page is hidden from search: a content problem the owner caused, not a missing keyword.');
        $this->assertSame(S::Passing, $this->evaluateGrowth()['outcomes'][self::COVERAGE]->status);
    }

    public function test_a_failed_audit_is_not_a_technical_pass(): void
    {
        $this->publishSite([$this->page('home', 'Hello', false, true)]);
        $websiteId = (int) DB::table('websites')->where('business_id', $this->business->id)->value('id');
        $revisionId = (int) DB::table('websites')->where('id', $websiteId)->value('published_revision_id');
        DB::table('seo_audit_runs')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'website_id' => $websiteId, 'website_revision_id' => $revisionId,
            'rule_set_version' => 1, 'status' => 'failed', 'page_count' => 0, 'critical_count' => 0, 'warning_count' => 0, 'info_count' => 0, 'created_at' => now(),
        ]);

        $this->assertFalse($this->facts()->set('seo')->get('audit_ran'));
        $this->assertSame(S::Insufficient, $this->evaluateGrowth()['outcomes']['seo.technical_findings:v1']->status, 'A failed audit is "not checked", never "no issues".');
    }

    // ── rank facts from stored observations ──────────────────────────────

    public function test_with_no_rank_observations_the_rank_rules_are_insufficient_not_all_clear(): void
    {
        $this->target($this->keyword('photo booth rental'));

        $rank = $this->facts()->set('rank');

        $this->assertTrue($rank->isAvailable());
        $this->assertSame(1, $rank->get('tracked_count'));
        $this->assertSame(0, $rank->get('judged_count'));

        $outcomes = $this->evaluateGrowth()['outcomes'];
        $this->assertSame(S::Insufficient, $outcomes[self::DROP]->status);
        $this->assertSame(S::Insufficient, $outcomes[self::NEAR_TOP]->status);
    }

    public function test_drops_improvements_and_near_misses_come_only_from_stored_organic_results(): void
    {
        $big = $this->tracked('big drop', 4, 12);
        $small = $this->tracked('small drop', 4, 7);              // 3 places: under the 5-place threshold
        $out = $this->tracked('fell out', 5, null, null, 1, 'not_found');
        $this->tracked('moved up', 9, 5);
        $near = $this->tracked('near miss', 14, 14);
        $this->tracked('first page', 3, 3);
        $unmatched = $this->tracked('unmatched', 5, null, null, 1, 'not_matched');

        $rank = $this->facts()->set('rank');

        $this->assertSame(7, $rank->get('tracked_count'));
        $this->assertSame(7, $rank->get('judged_count'));
        $this->assertSame(7, $rank->get('comparable_count'));

        $drops = collect($rank->get('drops'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$big, $out], $drops->keys()->all(), 'Only a meaningful drop or a fall out of the results.');
        $this->assertSame('down', $drops[$big]['kind']);
        $this->assertSame(8, $drops[$big]['amount']);
        $this->assertSame('dropped', $drops[$out]['kind']);
        $this->assertNotContains($small, $drops->keys()->all());
        $this->assertNotContains($unmatched, $drops->keys()->all(), 'Not matched is not a position and never a drop.');

        // Position 11-20 at the latest check: the big drop (12) and the near miss (14).
        $this->assertEqualsCanonicalizing([$big, $near], array_column($rank->get('near_top'), 'id'));
        $this->assertSame(14, collect($rank->get('near_top'))->firstWhere('id', $near)['position']);
        $this->assertSame(1, $rank->get('improved_count'));
        $this->assertSame(3, $rank->get('top10_count'), 'moved up (5), first page (3) and small drop (7).');
    }

    public function test_the_drop_threshold_comes_from_growth_config(): void
    {
        $this->tracked('three place drop', 4, 7);

        $this->assertSame([], $this->facts()->set('rank')->get('drops'));

        config(['growth.thresholds.rank_drop_positions' => 3]);

        $this->assertCount(1, $this->facts()->set('rank')->get('drops'));
    }

    public function test_a_stale_result_is_not_evidence_and_local_results_are_ignored(): void
    {
        $this->tracked('stale phrase', 4, 15, null, 30);   // checked 30 days ago: past the 7-day window

        $local = $this->target($this->keyword('local only'));
        $this->obs($local, 'local', 2, now()->subDays(6));
        $this->obs($local, 'local', 9, now()->subDay());

        $rank = $this->facts()->set('rank');

        $this->assertSame(0, $rank->get('judged_count'));
        $this->assertSame([], $rank->get('drops'));
        $this->assertSame([], $rank->get('near_top'));

        config(['seo.rank_tracking.stale_after_days' => 60]);
        $this->assertSame(1, $this->facts()->set('rank')->get('judged_count'), 'The freshness window is the rank module\'s own config.');
    }

    public function test_stopped_targets_archived_keywords_and_archived_locations_are_left_out(): void
    {
        $stopped = $this->target($this->keyword('stopped phrase'), 'stopped');
        $this->obs($stopped, 'organic', 4, now()->subDays(4));
        $this->obs($stopped, 'organic', 15, now()->subDay());

        $archivedKeyword = $this->target($this->keyword('archived phrase', null, 'archived'));
        $this->obs($archivedKeyword, 'organic', 4, now()->subDays(4));
        $this->obs($archivedKeyword, 'organic', 15, now()->subDay());

        $closed = $this->secondLocation('Closed site');
        $this->tracked('closed site phrase', 4, 15, $closed->id);
        $this->archiveLocation($closed);

        $this->assertSame(0, $this->facts()->set('rank')->get('tracked_count'));
    }

    public function test_a_business_without_rank_tracking_is_not_entitled_and_its_rules_are_not_applicable(): void
    {
        $this->tracked('big drop', 4, 12);
        DB::table('workspace_plan_features')->where('feature_key', 'seo_rank_tracking')->delete();

        $this->assertSame(GrowthFactStatus::NotEntitled, $this->facts()->set('rank')->status);

        $outcomes = $this->evaluateGrowth()['outcomes'];
        $this->assertSame(S::NotApplicable, $outcomes[self::DROP]->status);
        $this->assertSame(S::NotApplicable, $outcomes[self::NEAR_TOP]->status);
        $this->assertCount(0, $this->growthOpportunities(self::DROP));
    }

    public function test_search_console_stays_neutral_unavailable(): void
    {
        $this->assertSame(GrowthFactStatus::Unavailable, $this->facts()->set('search_console')->status);
    }

    // ── one root problem, one recommendation ─────────────────────────────

    public function test_end_to_end_each_root_problem_becomes_one_opportunity_per_location(): void
    {
        $this->publishSite([$this->page('home', 'photo booth rental and wedding booth and party booth.', false, true)]);

        $this->tracked('photo booth rental', 4, 13, $this->primaryLocation->id);   // dropped 9
        $this->tracked('wedding booth', 14, 14);                                    // near miss, Business-wide
        $this->tracked('party booth', 4, 12);                                       // dropped 8, Business-wide
        $this->tracked('karaoke hire', 3, 13);                                       // dropped AND not on the site

        $outcomes = $this->evaluateGrowth()['outcomes'];

        // Coverage owns the keyword the site never mentions.
        $this->assertSame(S::Finding, $outcomes[self::COVERAGE]->status);
        $this->assertSame(1, $outcomes[self::COVERAGE]->findings[0]->evidence['count']);

        $dropIds = [];
        foreach ($outcomes[self::DROP]->findings as $finding) {
            $dropIds[$finding->locationId ?? 0] = $finding->evidence['count'];
        }
        ksort($dropIds);
        $this->assertSame([0 => 1, $this->primaryLocation->id => 1], $dropIds, 'party booth (Business-wide) and photo booth rental (its Location); karaoke hire is the coverage gap.');
        $this->assertNotContains('karaoke hire', collect($outcomes[self::DROP]->findings)->flatMap(fn ($f) => $f->evidence['phrases'])->all());

        $this->assertSame(S::Finding, $outcomes[self::NEAR_TOP]->status);
        $this->assertSame(['wedding booth'], $outcomes[self::NEAR_TOP]->findings[0]->evidence['phrases']);

        $this->assertCount(2, $this->growthOpportunities(self::DROP));
        $this->assertCount(1, $this->growthOpportunities(self::NEAR_TOP));
        $this->assertCount(1, $this->growthOpportunities(self::COVERAGE));
    }

    // ── cost: stored rows only ───────────────────────────────────────────

    public function test_the_rank_reader_costs_the_same_for_one_and_many_targets_and_makes_no_request(): void
    {
        $reader = app(GrowthRankFactReader::class);
        $count = function () use ($reader): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $reader->read($this->business->fresh(), CarbonImmutable::now(), new GrowthThresholds());
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->tracked('one', 4, 12);
        $one = $count();

        for ($i = 0; $i < 12; $i++) {
            $this->tracked('many ' . $i, 4, 9 + $i);
        }

        $this->assertSame($one, $count(), 'Constant queries however many targets exist.');
        $this->assertLessThanOrEqual(6, $one);
    }
}
