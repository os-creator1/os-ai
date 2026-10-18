<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankObservationStatus;
use App\Enums\Seo\SeoRankRunState;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\SeoRankCheckExecutor;
use App\Library\Seo\Rank\SeoRankCheckPlanner;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * The paid pipeline end to end against the FAKE provider: plan -> reserve ->
 * submit -> poll -> observation, plus the idempotency and ambiguity rules that
 * stop retries from double-spending.
 */
class SeoRankPipelineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function plan($target, ?CarbonImmutable $now = null): array
    {
        return app(SeoRankCheckPlanner::class)->planScheduled($target->fresh(['business']), $now);
    }

    private function runAll(): void
    {
        $executor = app(SeoRankCheckExecutor::class);

        foreach (SeoRankCheckRun::query()->orderBy('id')->get() as $run) {
            $executor->submit($run->id);
        }

        foreach (SeoRankCheckRun::query()->orderBy('id')->get() as $run) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->subMinute()]);
            $executor->poll($run->id);
        }
    }

    public function test_a_scheduled_check_runs_end_to_end_and_records_normalized_observations(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        FakeSeoRankProvider::willReturn('organic', 'photo booth rental', [
            $this->item(1, 'competitor.com'),
            $this->item(7, 'www.photoboothco.com', 'https://www.photoboothco.com/rentals'),
        ]);
        FakeSeoRankProvider::willReturn('local', 'photo booth rental', [
            $this->item(2, 'other.com', null, '+1 312 555 0000'),
            $this->item(3, null, null, '(555) 010-1234'),
        ]);

        $decisions = $this->plan($target);
        $this->assertCount(2, $decisions);
        $this->assertTrue($decisions[0]->allowed && $decisions[1]->allowed);
        $this->assertSame(2, SeoRankProviderLedger::query()->where('status', 'reserved')->count());

        $this->runAll();

        $organic = SeoRankObservation::query()->where('check_type', 'organic')->firstOrFail();
        $this->assertSame(SeoRankObservationStatus::Found, $organic->status);
        $this->assertSame(7, $organic->position);
        $this->assertSame('/rentals', $organic->result_path);
        $this->assertSame('photoboothco.com', $organic->result_domain);
        $this->assertSame(100, $organic->depth_checked);

        $local = SeoRankObservation::query()->where('check_type', 'local')->firstOrFail();
        $this->assertSame(3, $local->position);
        $this->assertSame('phone', $local->match_basis);
        $this->assertSame(10, $local->depth_checked);

        $this->assertSame(2, SeoRankCheckRun::query()->where('state', SeoRankRunState::Completed->value)->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->where('status', 'reserved')->count());
        $this->assertNotNull($target->fresh()->last_checked_at);

        // Standard queue, mobile, bounded depths — nothing else was asked of the provider.
        foreach (FakeSeoRankProvider::$submitted as $request) {
            $this->assertSame('mobile', $request->device);
            $this->assertContains($request->depth, [100, 10]);
        }
    }

    public function test_the_same_cadence_window_never_creates_a_second_paid_run(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');

        $first = $this->plan($target, $now);
        $this->assertTrue($first[0]->allowed);

        // Scheduler ticks again (or a job is retried) inside the same window.
        $target->forceFill(['next_check_at' => null])->save();
        $again = $this->plan($target, $now->addHours(3));

        $this->assertFalse($again[0]->allowed);
        $this->assertSame('already_scheduled', $again[0]->reason);
        $this->assertSame(2, SeoRankCheckRun::query()->count());
        $this->assertSame(2, SeoRankProviderLedger::query()->count());
    }

    public function test_a_duplicate_submit_job_does_not_submit_twice(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$completeImmediately = false;

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();

        $executor = app(SeoRankCheckExecutor::class);
        $executor->submit($run->id);
        $executor->submit($run->id);
        $executor->submit($run->id);

        $this->assertSame(1, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(SeoRankRunState::Submitted, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->provider_task_id);
    }

    public function test_a_failed_or_pending_poll_never_resubmits(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$completeImmediately = false;

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();
        $executor = app(SeoRankCheckExecutor::class);
        $executor->submit($run->id);

        for ($i = 0; $i < 4; $i++) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->subMinute()]);
            $executor->poll($run->id);
        }

        $this->assertSame(1, FakeSeoRankProvider::$submitCalls, 'Polling must never submit.');
        $this->assertSame(4, FakeSeoRankProvider::$fetchCalls);
        $this->assertSame(SeoRankRunState::Submitted, $run->fresh()->state);
        $this->assertSame(4, $run->fresh()->poll_attempts);
    }

    public function test_no_result_is_not_found_with_a_null_position_never_zero_or_101(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::willReturn('organic', 'photo booth rental', [$this->item(1, 'a.com'), $this->item(100, 'b.com')]);

        $this->plan($target);
        $this->runAll();

        $organic = SeoRankObservation::query()->where('check_type', 'organic')->firstOrFail();
        $this->assertSame(SeoRankObservationStatus::NotFound, $organic->status);
        $this->assertNull($organic->position);
        $this->assertNull($organic->result_url);
    }

    public function test_a_definitively_rejected_submit_is_retried_with_the_same_reservation_then_released(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$submitFailure = SeoRankProviderException::rejected(SeoRankProviderException::CODE_RATE_LIMIT);

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();
        $executor = app(SeoRankCheckExecutor::class);

        $executor->submit($run->id);
        $this->assertSame(SeoRankRunState::Scheduled, $run->fresh()->state);
        $this->assertSame(1, SeoRankProviderLedger::query()->where('seo_rank_check_run_id', $run->id)->count());
        $this->assertSame('reserved', $this->ledgerStatus($run));

        for ($i = 0; $i < 3; $i++) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->subMinute()]);
            $executor->submit($run->id);
        }

        $this->assertSame(SeoRankRunState::FailedTerminal, $run->fresh()->state);
        $this->assertSame('provider_rate_limit', $run->fresh()->error_code);
        $this->assertSame('released', $this->ledgerStatus($run), 'Nothing was spent, so the reservation is released.');
        $this->assertSame(0, count(FakeSeoRankProvider::$submitted));
    }

    public function test_an_ambiguous_submit_holds_the_run_keeps_the_cost_counted_and_never_resubmits(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$submitFailure = SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
        FakeSeoRankProvider::$ambiguousButCreated = true;

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();
        $executor = app(SeoRankCheckExecutor::class);

        $executor->submit($run->id);
        $executor->submit($run->id);
        $executor->submit($run->id);

        $this->assertSame(SeoRankRunState::Held, $run->fresh()->state);
        $this->assertSame(1, FakeSeoRankProvider::$submitCalls, 'An ambiguous outcome is never resubmitted.');
        $this->assertSame('held', $this->ledgerStatus($run));
        $this->assertSame(6000, (int) SeoRankProviderLedger::query()->where('seo_rank_check_run_id', $run->id)->value('reserved_micros'));

        // Reconciliation recovers the provider's task by our run-uid tag; still one submit.
        FakeSeoRankProvider::$submitFailure = null;
        $this->assertSame(1, $executor->reconcileHeld());
        $this->assertSame(SeoRankRunState::Submitted, $run->fresh()->state);
        $this->assertSame(1, FakeSeoRankProvider::$submitCalls);
        $this->assertSame('committed', $this->ledgerStatus($run));
    }

    public function test_a_held_run_with_no_provider_task_closes_after_the_window_without_releasing_its_cost(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$submitFailure = SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();
        app(SeoRankCheckExecutor::class)->submit($run->id);
        $this->assertSame(SeoRankRunState::Held, $run->fresh()->state);

        SeoRankCheckRun::query()->whereKey($run->id)->update(['started_at' => now()->subHours(30)]);
        app(SeoRankCheckExecutor::class)->reconcileHeld();

        $this->assertSame(SeoRankRunState::FailedTerminal, $run->fresh()->state);
        $this->assertSame('submit_unconfirmed', $run->fresh()->error_code);
        $this->assertSame('committed', $this->ledgerStatus($run), 'Possibly charged: stays counted.');
    }

    public function test_a_crashed_submission_is_held_not_resubmitted(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        $this->plan($target);
        $run = SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail();
        SeoRankCheckRun::query()->whereKey($run->id)->update(['state' => 'submitting', 'updated_at' => now()->subMinutes(30)]);

        app(SeoRankCheckExecutor::class)->holdStuckSubmissions();
        app(SeoRankCheckExecutor::class)->submit($run->id);

        $this->assertSame(SeoRankRunState::Held, $run->fresh()->state);
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame('held', $this->ledgerStatus($run));
    }

    public function test_stopping_tracking_before_submit_cancels_without_spend(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        $this->plan($target);
        app(\App\Library\Seo\Rank\SeoRankTargetManager::class)->stop((int) $owner->user_id, $business, $target->uid);

        $this->runAll();

        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, SeoRankProviderLedger::query()->where('status', '!=', 'released')->count());
    }

    public function test_the_provider_switch_off_releases_reservations_and_makes_no_call(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        $this->plan($target);
        config(['seo.rank_tracking.enabled' => false]);
        $this->runAll();

        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, SeoRankProviderLedger::query()->where('status', '!=', 'released')->count());
    }

    public function test_provider_reported_cost_replaces_the_estimate_in_the_ledger(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        FakeSeoRankProvider::$costMicros = 4200;

        $this->plan($target);
        $this->runAll();

        $this->assertSame(2, SeoRankProviderLedger::query()->where('status', 'committed')->where('actual_micros', 4200)->count());
    }

    private function ledgerStatus(SeoRankCheckRun $run): ?string
    {
        return SeoRankProviderLedger::query()->where('seo_rank_check_run_id', $run->id)->value('status');
    }
}
