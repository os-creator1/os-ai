<?php

namespace Tests\Feature\Growth;

use App\Events\DocumentPaymentFailed;
use App\Jobs\Growth\RunGrowthEvaluation;
use App\Library\Growth\GrowthEvaluationService;
use App\Library\Growth\GrowthEvaluationTrigger;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthScoreCalculator;
use App\Library\Growth\GrowthScoreReader;
use App\Library\Growth\GrowthViewer;
use App\Models\GrowthScoreSnapshot;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * Evaluation behaviour end to end: score history and versioning, worker
 * isolation, scheduling, event triggers, priority and the query budget.
 */
class GrowthEvaluationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    private function snapshots()
    {
        return GrowthScoreSnapshot::query()->where('business_id', $this->business->id)->orderBy('snapshot_date')->get();
    }

    // ── Score history ────────────────────────────────────────────────────

    public function test_an_evaluation_records_one_snapshot_per_day_and_replaces_it_on_a_re_run(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->evaluateGrowth();

        $rows = $this->snapshots();
        $this->assertCount(1, $rows);
        $this->assertSame(GrowthScoreCalculator::ALGORITHM_VERSION, (int) $rows[0]->algorithm_version);
        $this->assertSame(now()->toDateString(), $rows[0]->snapshot_date->toDateString());
        $this->assertArrayHasKey('lead_response', $rows[0]->category_scores);
        $this->assertNotNull($rows[0]->breakdown['lead_response']['rules']);
    }

    public function test_the_snapshot_says_which_modules_could_not_be_read(): void
    {
        $this->evaluateGrowth();

        $status = $this->snapshots()->first()->metrics['domain_status'];

        $this->assertSame('unavailable', $status['ads']);
        $this->assertSame('unavailable', $status['rank']);
        $this->assertSame('available', $status['crm']);
    }

    public function test_a_new_algorithm_version_writes_beside_the_old_rows_and_never_rewrites_them(): void
    {
        $old = GrowthScoreSnapshot::create([
            'business_id' => $this->business->id, 'snapshot_date' => now()->subDays(3)->toDateString(), 'algorithm_version' => 0,
            'overall_score' => 55, 'scored_category_count' => 2, 'total_category_count' => 9, 'applicable_rule_count' => 4,
            'category_scores' => ['lead_response' => 55], 'breakdown' => [], 'computed_at' => now()->subDays(3),
        ]);

        $this->evaluateGrowth();

        $this->assertSame(55, (int) $old->fresh()->overall_score, 'an older version is never recomputed');
        $this->assertSame(2, $this->snapshots()->count());
        $this->assertSame(1, GrowthScoreSnapshot::where('business_id', $this->business->id)->where('algorithm_version', GrowthScoreCalculator::ALGORITHM_VERSION)->count());
    }

    public function test_movement_compares_only_snapshots_of_the_current_version(): void
    {
        $make = fn (int $version, int $daysAgo, int $overall, array $cats) => GrowthScoreSnapshot::create([
            'business_id' => $this->business->id, 'snapshot_date' => now()->subDays($daysAgo)->toDateString(), 'algorithm_version' => $version,
            'overall_score' => $overall, 'scored_category_count' => count($cats), 'total_category_count' => 9, 'applicable_rule_count' => 3,
            'category_scores' => $cats, 'breakdown' => [], 'computed_at' => now()->subDays($daysAgo),
        ]);

        $make(0, 10, 20, ['lead_response' => 20]);        // another formula: must be ignored
        $make(1, 9, 71, ['lead_response' => 60, 'website' => 82]);
        $latest = $make(1, 0, 78, ['lead_response' => 70, 'website' => 85, 'seo' => 90]);

        $reader = app(GrowthScoreReader::class);
        $baseline = $reader->baseline($this->business, $latest);
        $movement = $reader->movement($latest, $baseline);

        $this->assertSame(71, $movement['from']);
        $this->assertSame(7, $movement['delta']);
        $this->assertSame('Your Growth Score improved from 71 to 78.', $movement['sentence']);
        $this->assertSame(['Lead response' => 10, 'Website' => 3], collect($movement['contributors'])->pluck('delta', 'label')->all(), 'seo has no earlier score so it is not a "contributor"');
    }

    public function test_there_is_no_movement_without_an_earlier_snapshot(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $reader = app(GrowthScoreReader::class);
        $latest = $reader->latest($this->business);

        $this->assertNull($reader->baseline($this->business, $latest));
        $this->assertNull($reader->movement($latest, null));
    }

    public function test_snapshots_are_kept_for_thirteen_months_and_pruned_after(): void
    {
        $row = fn (int $daysAgo) => GrowthScoreSnapshot::create([
            'business_id' => $this->business->id, 'snapshot_date' => now()->subDays($daysAgo)->toDateString(), 'algorithm_version' => 1,
            'overall_score' => 50, 'scored_category_count' => 1, 'total_category_count' => 9, 'applicable_rule_count' => 1,
            'category_scores' => [], 'breakdown' => [], 'computed_at' => now()->subDays($daysAgo),
        ]);
        $row(400);   // 13+ months: kept
        $row(500);   // far older: pruned

        $this->evaluateGrowth();

        $dates = $this->snapshots()->pluck('snapshot_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertContains(now()->subDays(400)->toDateString(), $dates);
        $this->assertNotContains(now()->subDays(500)->toDateString(), $dates);
    }

    public function test_the_score_ignores_unavailable_categories_and_states_how_many_it_used(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();

        $s = $this->snapshots()->first();

        $this->assertLessThan(9, $s->scored_category_count, 'Ads / Booking etc. without data are not scored');
        $this->assertNull($s->category_scores['ads']);
        $this->assertSame(9, (int) $s->total_category_count);
    }

    // ── Isolation ────────────────────────────────────────────────────────

    public function test_one_failing_reader_fails_only_its_workers_and_resolves_nothing(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $before = $this->growthOpportunities('crm.unanswered_new_leads:v1')->first();
        $this->assertSame('current', $before->freshness->value);

        $real = app(GrowthFactSnapshotBuilder::class);
        $this->partialMock(GrowthFactSnapshotBuilder::class, function ($mock) use ($real): void {
            $mock->shouldReceive('build')->andReturnUsing(function ($business, $now = null) use ($real) {
                $s = $real->build($business, $now);

                return new GrowthFactSnapshot($s->business, $s->now, $s->thresholds, $s->sets(), $s->locationNames, ['documents']);
            });
        });

        $result = app(GrowthEvaluationService::class)->evaluate($this->business->fresh());

        $this->assertSame('failed_reader', $result['workers']['sales'], 'sales reads the failed documents domain');
        $this->assertSame('succeeded', $result['workers']['website'], 'an unrelated worker is unaffected');
        $this->assertSame('succeeded', $result['workers']['reputation']);
        $this->assertSame('current', $before->fresh()->freshness->value, 'a broken reader never makes existing findings look resolved');
        $this->assertNull($result['score'], 'no score is written from a half-read evaluation');
        $this->assertSame(1, OpportunityRun::where('business_id', $this->business->id)->where('worker_key', 'sales')->where('status', 'failed')->count());
    }

    public function test_the_engine_off_switch_runs_nothing(): void
    {
        config(['opportunity.enabled' => false]);

        $this->assertFalse($this->evaluateGrowth()['ran']);
        $this->assertSame(0, OpportunityRun::count());
        $this->assertSame(0, GrowthScoreSnapshot::count());
    }

    public function test_a_business_without_the_ai_coo_entitlement_is_not_evaluated(): void
    {
        DB::table('workspace_plan_features')->where('feature_key', 'ai_coo_basic')->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $result = $this->evaluateGrowth();

        $this->assertFalse($result['ran']);
        $this->assertSame('not_entitled', $result['reason']);
    }

    // ── Scheduling ───────────────────────────────────────────────────────

    public function test_the_daily_sweep_dispatches_once_per_business_per_day(): void
    {
        Queue::fake();

        Artisan::call('growth:evaluate');
        Artisan::call('growth:evaluate');

        Queue::assertPushed(RunGrowthEvaluation::class, 1);
        Queue::assertPushed(RunGrowthEvaluation::class, fn ($job) => $job->businessId === $this->business->id);
    }

    public function test_the_sweep_is_bounded_by_its_limit_and_validates_it(): void
    {
        Queue::fake();

        $this->assertSame(\Illuminate\Console\Command::INVALID, Artisan::call('growth:evaluate', ['--limit' => '0']));
        Queue::assertNothingPushed();
    }

    public function test_the_sweep_is_a_no_op_while_the_engine_is_off(): void
    {
        config(['opportunity.enabled' => false]);
        Queue::fake();

        Artisan::call('growth:evaluate');

        Queue::assertNothingPushed();
    }

    public function test_a_burst_of_events_costs_one_evaluation_per_window(): void
    {
        Queue::fake();
        $trigger = app(GrowthEvaluationTrigger::class);

        $this->assertTrue($trigger->triggerFromEvent($this->business->id));
        $this->assertFalse($trigger->triggerFromEvent($this->business->id));
        $this->assertFalse($trigger->triggerFromEvent($this->business->id));

        Queue::assertPushed(RunGrowthEvaluation::class, 1);
    }

    public function test_a_failed_payment_event_requests_a_re_evaluation(): void
    {
        Queue::fake();
        $documentId = $this->document('signed', ['signed_at' => now()->subDays(6)], [[30000, 3]]);

        event(new DocumentPaymentFailed($documentId, 1, $this->business->id));

        Queue::assertPushed(RunGrowthEvaluation::class, fn ($job) => $job->businessId === $this->business->id);
    }

    public function test_events_do_nothing_while_the_engine_is_off(): void
    {
        config(['opportunity.enabled' => false]);
        Queue::fake();

        event(new DocumentPaymentFailed(1, 1, $this->business->id));

        // Other modules (Automations) legitimately queue their own listeners for this event; Growth must queue nothing.
        Queue::assertNotPushed(AppJobsGrowthRunGrowthEvaluation::class);
    }

    // ── Priority ─────────────────────────────────────────────────────────

    public function test_high_impact_outranks_low_impact_in_the_engines_priority_order(): void
    {
        $documentId = $this->document('signed', ['signed_at' => now()->subDays(20)], [[70000, -3]]);   // overdue: impact 5
        $this->evaluateGrowth();                                                                         // citations not checked: impact 2
        $this->assertNotNull($documentId);

        $viewer = GrowthViewer::forTest(1, [$this->primaryLocation->id], true);
        $top = app(GrowthOpportunityReader::class)->top($this->business, $viewer, 10);

        $this->assertSame('payments.overdue_balance:v1', $top->first()->type);
        $this->assertGreaterThan((int) $top->last()->priority_score, (int) $top->first()->priority_score);
    }

    public function test_a_canonical_deal_value_raises_priority_and_no_value_invents_none(): void
    {
        $this->unansweredDeal(48, 90000, 'High value');
        $this->evaluateGrowth();
        $withValue = $this->growthOpportunities('crm.unanswered_new_leads:v1')->first();
        $this->assertSame(5, (int) $withValue->impact, 'a value at or above the high-value threshold lifts impact');

        DB::table('crm_opportunities')->update(['value_minor' => null, 'currency_code' => null]);
        $this->evaluateGrowth();
        $without = $withValue->fresh();

        $this->assertSame(4, (int) $without->impact, 'with no canonical value the base impact applies');
        $this->assertNull($without->evidence[0]['observed_value']['value_minor']);
        $this->assertNull($without->evidence[0]['observed_value']['currency']);
    }

    public function test_lower_confidence_lowers_priority_and_is_stored_honestly(): void
    {
        $scorer = app(\App\Library\Opportunity\OpportunityScorer::class);

        $certain = $scorer->computePriorityScore(4, 4, 0, 1.0, 5, 1);
        $inferred = $scorer->computePriorityScore(4, 4, 0, 0.8, 5, 1);
        $this->assertGreaterThan($inferred, $certain, 'everything else equal, confidence lowers priority');

        $this->conversation([['incoming', 30]]);
        $this->evaluateGrowth();
        $conversation = $this->growthOpportunities('conversations.inbound_awaiting_reply:v1')->first();

        $this->assertEqualsWithDelta(0.8, (float) $conversation->confidence, 0.001, 'a strong inference is stored as moderate, not as certain');
    }

    // ── Query budget ─────────────────────────────────────────────────────

    public function test_a_full_evaluation_costs_the_same_queries_however_many_deals_exist(): void
    {
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(GrowthEvaluationService::class)->evaluate($this->business->fresh(), CarbonImmutable::now());
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->unansweredDeal();
        $this->conversation([['incoming', 30]]);
        $measure();                // warm in-process caches
        $small = $measure();

        for ($i = 0; $i < 30; $i++) {
            $this->unansweredDeal(30 + $i, 1000 + $i);
            $this->conversation([['incoming', 30 + $i]]);
        }

        $this->assertSame($small, $measure(), 'deals and conversations are rows, not queries');
    }

    public function test_viewing_the_growth_center_costs_a_bounded_number_of_reads_not_one_per_opportunity(): void
    {
        $count = function (): int {
            $viewer = GrowthViewer::forTest(1, [$this->primaryLocation->id], true);
            $reader = app(GrowthOpportunityReader::class);
            $presenter = app(\App\Library\Growth\GrowthOpportunityPresenter::class);
            $names = \App\Library\Growth\GrowthOpportunityPresenter::locationNames($this->business->id);

            DB::flushQueryLog();
            DB::enableQueryLog();
            foreach ($reader->top($this->business, $viewer, 50) as $o) {
                $presenter->present($o, $names, $this->workspace->uid, $this->business->uid);
            }
            $reader->summary($this->business, $viewer);
            $reader->stateCounts($this->business, $viewer);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->unansweredDeal();
        $this->evaluateGrowth();
        $few = $count();
        $this->assertGreaterThan(1, $this->growthOpportunities()->count());

        for ($i = 0; $i < 3; $i++) {
            $this->bookingType();
            $this->document('sent', ['sent_at' => now()->subDays(5 + $i)], [], 1000);
        }
        $this->evaluateGrowth();

        $this->assertSame($few, $count(), 'presenting more cards adds no queries');
    }
}
