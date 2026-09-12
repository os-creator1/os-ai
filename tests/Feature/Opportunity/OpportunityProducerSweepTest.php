<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer;
use App\Library\Opportunity\OpportunityProducerTrigger;
use App\Models\Business;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * COO C-1 — the daily safety sweep: contract §7.3 proof T-C1-2 (bounded
 * batches, one dispatch per Business per day, idempotent on a second run, no
 * unbounded scan) and the sweep half of T-C1-4.
 */
class OpportunityProducerSweepTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const COMMAND = 'opportunity:dispatch-business-advisor';

    /** Query counter for the flatness proof. */
    private int $sweptQueries = 0;

    private function enableEngine(): void
    {
        config(['opportunity.enabled' => true]);
    }

    /**
     * @return array<int, Business>
     */
    private function businesses(int $count): array
    {
        $businesses = [];

        for ($i = 0; $i < $count; $i++) {
            $businesses[] = $this->activeBusinessForOpportunities();
        }

        return $businesses;
    }

    private function dispatchRows(): int
    {
        return DB::table('opportunity_producer_dispatches')->count();
    }

    /**
     * The fixture creates a DRAFT Business; only active ones are swept, since
     * a draft is not reachable in the customer shell.
     */
    private function activeBusinessForOpportunities(): Business
    {
        $business = $this->createBusinessForOpportunities();

        DB::table('businesses')->where('id', $business->id)->update([
            'status' => BusinessStatus::Active->value,
            'activated_at' => now(),
        ]);

        return $business->fresh();
    }

    // -----------------------------------------------------------------
    // T-C1-2 — bounded, one per Business per day, idempotent
    // -----------------------------------------------------------------

    public function test_t_c1_2_the_sweep_dispatches_one_run_for_each_eligible_business(): void
    {
        Queue::fake();
        $this->enableEngine();
        $businesses = $this->businesses(5);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('dispatched 5 Business Advisor producer run(s)')
            ->assertSuccessful();

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 5);

        foreach ($businesses as $business) {
            Queue::assertPushed(
                RunBusinessAdvisorOpportunityProducer::class,
                fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $business->id,
            );
        }
    }

    public function test_t_c1_2_running_the_sweep_twice_in_one_day_duplicates_nothing(): void
    {
        Queue::fake();
        $this->enableEngine();
        $this->businesses(4);

        $this->artisan(self::COMMAND)->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 4);

        // The same day again — an overlapping scheduler, a second server, or a
        // human re-running it.
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('dispatched 0 Business Advisor producer run(s)')
            ->assertSuccessful();

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 4);
    }

    public function test_t_c1_2_the_next_day_is_a_new_slot(): void
    {
        Queue::fake();
        $this->enableEngine();
        $this->businesses(2);

        $this->travelTo(CarbonImmutable::parse('2026-09-16 03:10:00'));
        $this->artisan(self::COMMAND)->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 2);

        // A day later the Businesses are eligible again — unless a successful
        // run landed inside the staleness window, which is the next test.
        $this->travelTo(CarbonImmutable::parse('2026-09-17 03:10:00'));
        $this->artisan(self::COMMAND)->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 4);

        $this->travelBack();
    }

    public function test_t_c1_2_a_business_with_a_recent_successful_run_is_filtered_out_in_sql(): void
    {
        Queue::fake();
        $this->enableEngine();
        $now = CarbonImmutable::parse('2026-09-16 03:10:00');
        $this->travelTo($now);

        $fresh = $this->activeBusinessForOpportunities();
        $stale = $this->activeBusinessForOpportunities();

        $this->createOpportunityRun($fresh, [
            'status' => OpportunityRunStatus::Succeeded->value,
            'started_at' => $now->subHours(2),
            'heartbeat_at' => $now->subHours(2),
            'completed_at' => $now->subHours(2),
        ]);
        $this->createOpportunityRun($stale, [
            'status' => OpportunityRunStatus::Succeeded->value,
            'started_at' => $now->subHours(30),
            'heartbeat_at' => $now->subHours(30),
            'completed_at' => $now->subHours(30),
        ]);

        // The ineligible Business is excluded by the candidate query itself,
        // so it never becomes a claim or a row.
        $candidates = app(OpportunityProducerDispatchRepository::class)->sweepCandidates(
            OpportunityWorkerKey::BusinessAdvisor,
            $now,
            $now->subHours(24),
            0,
            100,
        );

        $this->assertSame([$stale->id], $candidates->all());

        $this->artisan(self::COMMAND)->assertSuccessful();

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $stale->id,
        );

        $this->travelBack();
    }

    public function test_t_c1_2_candidates_arrive_in_bounded_deterministic_keyset_pages(): void
    {
        $this->enableEngine();
        $now = CarbonImmutable::parse('2026-09-16 03:10:00');
        $businesses = $this->businesses(6);
        $ids = array_map(fn (Business $business) => $business->id, $businesses);
        sort($ids);

        $repository = app(OpportunityProducerDispatchRepository::class);

        $firstPage = $repository->sweepCandidates(OpportunityWorkerKey::BusinessAdvisor, $now, $now->subHours(24), 0, 2);
        $secondPage = $repository->sweepCandidates(OpportunityWorkerKey::BusinessAdvisor, $now, $now->subHours(24), $firstPage->last(), 2);
        $thirdPage = $repository->sweepCandidates(OpportunityWorkerKey::BusinessAdvisor, $now, $now->subHours(24), $secondPage->last(), 2);

        $this->assertCount(2, $firstPage, 'A page is capped by its limit.');
        $this->assertSame(array_slice($ids, 0, 2), $firstPage->all(), 'Ordered by id, ascending.');
        $this->assertSame(array_slice($ids, 2, 2), $secondPage->all(), 'The next page continues after the last id.');
        $this->assertSame(array_slice($ids, 4, 2), $thirdPage->all());

        // Pages never overlap, so no Business is considered twice in one pass.
        $this->assertSame($ids, array_merge($firstPage->all(), $secondPage->all(), $thirdPage->all()));
    }

    public function test_t_c1_2_the_sweep_never_reads_the_whole_table_and_stays_flat_as_businesses_grow(): void
    {
        Queue::fake();
        $this->enableEngine();

        // One listener for the whole test; the counter is what gets reset, so
        // the two measurements are directly comparable.
        DB::listen(function () : void {
            $this->sweptQueries++;
        });

        $this->businesses(4);
        $this->sweptQueries = 0;
        $this->artisan(self::COMMAND, ['--limit' => 2, '--page' => 2])->assertSuccessful();
        $queriesForFour = $this->sweptQueries;

        // Twice the data, same bounded invocation: the query count must not
        // move with the size of the table.
        $this->businesses(4);
        $this->sweptQueries = 0;
        $this->artisan(self::COMMAND, ['--limit' => 2, '--page' => 2])->assertSuccessful();
        $queriesForEight = $this->sweptQueries;

        $this->assertGreaterThan(0, $queriesForFour);
        $this->assertSame(
            $queriesForFour,
            $queriesForEight,
            'A bounded sweep issues the same number of queries however many Businesses exist.'
        );
    }

    public function test_t_c1_2_the_limit_caps_one_invocation_and_the_next_continues(): void
    {
        Queue::fake();
        $this->enableEngine();
        $this->businesses(5);

        $this->artisan(self::COMMAND, ['--limit' => 2, '--page' => 2])
            ->expectsOutputToContain('Considered 2 Business(es); dispatched 2')
            ->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 2);

        // The two already claimed for today are gone from the candidate set,
        // so a capped run makes real progress rather than repeating itself.
        $this->artisan(self::COMMAND, ['--limit' => 2, '--page' => 2])->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 4);

        $this->artisan(self::COMMAND, ['--limit' => 10, '--page' => 10])->assertSuccessful();
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 5);
    }

    public function test_t_c1_2_a_healthy_active_run_consumes_the_day_without_a_second_job(): void
    {
        Queue::fake();
        $this->enableEngine();
        $now = CarbonImmutable::parse('2026-09-16 03:10:00');
        $this->travelTo($now);

        $business = $this->activeBusinessForOpportunities();
        $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Running->value,
            'started_at' => $now->subMinutes(2),
            'heartbeat_at' => $now->subMinute(),
        ]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('dispatched 0 Business Advisor producer run(s)')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $this->travelBack();
    }

    public function test_t_c1_2_only_active_businesses_are_swept(): void
    {
        Queue::fake();
        $this->enableEngine();
        $active = $this->activeBusinessForOpportunities();
        $inactive = $this->createBusinessForOpportunities();
        DB::table('businesses')->where('id', $inactive->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $active->id,
        );
    }

    public function test_the_sweep_rejects_a_nonsense_limit(): void
    {
        $this->enableEngine();

        $this->artisan(self::COMMAND, ['--limit' => '0'])->assertExitCode(2);
        $this->artisan(self::COMMAND, ['--page' => 'many'])->assertExitCode(2);
    }

    // -----------------------------------------------------------------
    // T-C1-4 — the sweep, disabled and enabled
    // -----------------------------------------------------------------

    public function test_t_c1_4_with_the_engine_disabled_the_sweep_writes_and_queues_nothing(): void
    {
        Queue::fake();
        config(['opportunity.enabled' => false]);
        $this->businesses(3);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Opportunity engine is disabled')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('opportunity_runs')->count());
        $this->assertSame(0, DB::table('opportunities')->count());
        $this->assertSame(0, $this->dispatchRows(), 'A disabled sweep leaves no coordination state behind.');
    }

    public function test_t_c1_4_with_the_engine_enabled_the_sweep_populates_the_queue_with_no_manual_step(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();

        // Exactly what the scheduler runs. No tinker, no Home visit, no AI.
        $this->artisan(self::COMMAND)->assertSuccessful();

        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $business->id,
        );
    }

    public function test_the_daily_sweep_is_registered_on_the_schedule(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, self::COMMAND));

        $this->assertCount(1, $events, 'The sweep must be scheduled, or the daily path needs a human.');
        $this->assertSame('10 3 * * *', $events->first()->expression, 'Once a day.');
    }

}
