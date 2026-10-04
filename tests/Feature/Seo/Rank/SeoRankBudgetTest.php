<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankTrigger;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\SeoRankBudgetDecision;
use App\Library\Seo\Rank\SeoRankCheckPlanner;
use App\Library\Seo\Rank\SeoRankEntitlement;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Support\RequestScopedCache;
use App\Models\Business;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * Every spend limit of the rank-tracking budget authority, exercised through
 * SeoRankCheckPlanner / SeoRankTrackingBudget against real database rows.
 * One scheduled planning pass reserves an organic check (100 results = 6000
 * micro-USD) and a local check (10 results = 600): 6600 per pass.
 */
class SeoRankBudgetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    private const PASS = 6600;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
        $this->now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    /** @return list<SeoRankBudgetDecision> */
    private function plan(SeoRankTarget $target, ?CarbonImmutable $at = null): array
    {
        // The scheduler only reaches a target when it is due.
        SeoRankTarget::query()->whereKey($target->id)->update(['next_check_at' => null]);

        return app(SeoRankCheckPlanner::class)->planScheduled($target->fresh(['business']), $at ?? $this->now);
    }

    /** @return list<SeoRankBudgetDecision> */
    private function manual(SeoRankTarget $target, int $userId, ?CarbonImmutable $at = null): array
    {
        return app(SeoRankCheckPlanner::class)->planManual($target->fresh(['business']), $userId, $at ?? $this->now);
    }

    /** @param list<SeoRankBudgetDecision> $decisions */
    private function reasons(array $decisions): array
    {
        return array_map(fn (SeoRankBudgetDecision $d) => $d->allowed ? 'allowed' : $d->reason, $decisions);
    }

    private function closeRuns(): void
    {
        SeoRankCheckRun::query()->update(['state' => 'completed']);
    }

    /** Insert one spent ledger row (with its run) the way the budget authority would have. */
    private function spend(
        SeoRankTarget $target,
        int $reserved,
        string $status = SeoRankProviderLedger::COMMITTED,
        ?int $actual = null,
        ?int $workspaceId = null,
        ?CarbonImmutable $day = null,
        string $trigger = 'scheduled',
    ): SeoRankCheckRun {
        $day ??= $this->now;
        $workspaceId ??= (int) Business::query()->whereKey($target->business_id)->value('workspace_id');

        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $target->business_id,
            'workspace_id' => $workspaceId,
            'seo_rank_target_id' => $target->id,
            'check_type' => 'organic',
            'trigger' => $trigger,
            'idempotency_key' => 'fill:' . Str::uuid(),
            'state' => 'completed',
            'provider' => 'dataforseo',
            'depth' => 100,
            'reserved_micros' => $reserved,
            'actual_micros' => $actual,
        ])->save();

        $ledger = new SeoRankProviderLedger();
        $ledger->forceFill([
            'business_id' => $target->business_id,
            'workspace_id' => $workspaceId,
            'provider' => 'dataforseo',
            'operation' => 'organic_serp',
            'seo_rank_check_run_id' => $run->id,
            'usage_month' => $day->format('Y-m'),
            'usage_day' => $day->toDateString(),
            'reserved_micros' => $reserved,
            'actual_micros' => $actual,
            'status' => $status,
        ])->save();

        return $run;
    }

    private function observe(SeoRankTarget $target, CarbonImmutable $checkedAt): void
    {
        $run = $this->spend($target, 100, SeoRankProviderLedger::COMMITTED, 100, null, $checkedAt);

        $observation = new SeoRankObservation();
        $observation->forceFill([
            'business_id' => $target->business_id,
            'seo_rank_target_id' => $target->id,
            'seo_rank_check_run_id' => $run->id,
            'check_type' => 'organic',
            'status' => 'not_found',
            'depth_checked' => 100,
            'provider' => 'dataforseo',
            'search_location_code' => $target->search_location_code,
            'device' => 'mobile',
            'checked_at' => $checkedAt,
        ])->save();
    }

    private function trackedTarget(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $domain = 'photoboothco.com'): array
    {
        [$owner, $business, $workspace] = $this->rankTenant($tier, $domain);
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);

        return [$owner, $business, $workspace, $target, $keyword];
    }

    private function makeTrial(Workspace $workspace): void
    {
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $workspace->id)
            ->update(['trial_ends_at' => now()->addDays(30)]);

        // The assignment is memoized per request; raw edits need the memo flushed.
        app(RequestScopedCache::class)->flush();
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    // ---------------------------------------------------------------
    // Business monthly cap
    // ---------------------------------------------------------------

    public function test_a_scheduled_pass_reserves_organic_6000_and_local_600_in_the_ledger(): void
    {
        [, , , $target] = $this->trackedTarget();

        $decisions = $this->plan($target);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($decisions));
        $this->assertSame(self::PASS, (int) SeoRankProviderLedger::query()->sum('reserved_micros'));
        $this->assertSame([6000, 600], SeoRankProviderLedger::query()->orderBy('id')->pluck('reserved_micros')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls, 'Planning never calls the provider.');
    }

    public function test_core_cap_is_one_and_a_half_dollars_and_refuses_the_pass_that_would_exceed_it(): void
    {
        [, $business, , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        $plan = app(SeoRankEntitlement::class)->planFor($business, $this->now);
        $this->assertSame('core', $plan->tier);
        $this->assertSame(1_500_000, $plan->monthlyCapMicros);

        // Leave room for exactly one more pass: it is allowed, landing exactly on the cap.
        $this->spend($target, 1_500_000 - self::PASS);
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
        $this->closeRuns();

        $runs = SeoRankCheckRun::query()->count();
        $ledger = SeoRankProviderLedger::query()->count();

        // The next day's pass would exceed the cap by 1 micro: refused, nothing created.
        $next = $this->plan($target, $this->now->addDay());

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($next));
        $this->assertTrue($next[0]->isBudgetPause());
        $this->assertSame($runs, SeoRankCheckRun::query()->count());
        $this->assertSame($ledger, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_growth_cap_is_four_and_a_half_dollars(): void
    {
        [, $business, , $target] = $this->trackedTarget(WorkspacePlanTier::Growth);

        $plan = app(SeoRankEntitlement::class)->planFor($business, $this->now);
        $this->assertSame('growth', $plan->tier);
        $this->assertSame(4_500_000, $plan->monthlyCapMicros);
        $this->assertSame(20, $plan->trackedTargets);
        $this->assertSame(1, $plan->cadenceDays);

        $this->spend($target, 4_500_000 - 599);

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($this->plan($target)));
        $this->assertSame(1, SeoRankCheckRun::query()->count(), 'Only the pre-existing spend row; nothing new.');
    }

    public function test_a_partial_fit_reserves_the_organic_check_but_refuses_the_local_check(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Growth);

        // Room for the 6000 organic reservation but one micro short of also fitting the 600 local one.
        $this->spend($target, 4_500_000 - self::PASS + 1);

        $decisions = $this->plan($target);

        $this->assertSame(['allowed', 'business_cap'], $this->reasons($decisions));
        $this->assertSame(1, SeoRankCheckRun::query()->where("idempotency_key", "like", "%:organic:%")->count());
        $this->assertSame(0, SeoRankCheckRun::query()->where('idempotency_key', 'like', '%:local:%')->count());
    }

    public function test_reservations_accumulate_across_days_until_the_next_check_would_exceed_the_cap(): void
    {
        config(['seo.rank_tracking.tiers.core.monthly_cap_micros' => 20_000]);
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        // 3 passes = 19_800 fit under 20_000; the 4th would reach 26_400.
        foreach ([0, 1, 2] as $offset) {
            $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target, $this->now->addDays($offset))), "day {$offset}");
            $this->closeRuns();
        }

        $this->assertSame(19_800, (int) SeoRankProviderLedger::query()->sum('reserved_micros'));

        $fourth = $this->plan($target, $this->now->addDays(3));

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($fourth));
        $this->assertSame(6, SeoRankCheckRun::query()->count());
        $this->assertSame(6, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_a_paid_plans_cap_window_is_the_calendar_month(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        // Last month's spend does not count against this month's cap.
        $this->spend($target, 1_500_000, SeoRankProviderLedger::COMMITTED, null, null, $this->now->subMonth());

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
    }

    // ---------------------------------------------------------------
    // Trial
    // ---------------------------------------------------------------

    public function test_a_trial_workspace_gets_the_trial_limits(): void
    {
        [, $business, $workspace] = $this->trackedTarget(WorkspacePlanTier::Growth);
        $this->makeTrial($workspace);

        $plan = app(SeoRankEntitlement::class)->planFor($business->fresh(), $this->now);

        $this->assertNotNull($plan);
        $this->assertSame('trial', $plan->tier);
        $this->assertTrue($plan->isTrial);
        $this->assertSame(5, $plan->trackedTargets);
        $this->assertSame(3, $plan->cadenceDays);
        $this->assertSame(500_000, $plan->monthlyCapMicros);
    }

    public function test_the_trial_cap_of_fifty_cents_is_enforced(): void
    {
        [, , $workspace, $target] = $this->trackedTarget(WorkspacePlanTier::Core);
        $this->makeTrial($workspace);

        $this->spend($target, 500_000 - self::PASS);
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
        $this->closeRuns();

        // The next 3-day window would exceed $0.50.
        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($this->plan($target, $this->now->addDays(3))));
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_trial_cadence_is_a_three_day_bucket_while_core_is_a_daily_bucket(): void
    {
        // Start of a 3-day bucket, at noon UTC.
        $days = intdiv($this->now->getTimestamp(), 86400);
        $base = CarbonImmutable::createFromTimestampUTC(($days - ($days % 3)) * 86400 + 12 * 3600);

        // TRIAL: same window for +0d and +2d, a new one at +3d.
        [, , $trialWorkspace, $trialTarget] = $this->trackedTarget(WorkspacePlanTier::Core);
        $this->makeTrial($trialWorkspace);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($trialTarget, $base)));
        $this->closeRuns();
        $this->assertSame(['already_scheduled', 'already_scheduled'], $this->reasons($this->plan($trialTarget, $base->addDays(2))));
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($trialTarget, $base->addDays(3))));
        $this->assertSame(4, SeoRankCheckRun::query()->where('business_id', $trialTarget->business_id)->count());
    }

    public function test_core_cadence_is_a_daily_bucket(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
        $this->closeRuns();

        // Scheduler ticks again the same UTC day (even hours later): idempotent.
        $this->assertSame(['already_scheduled', 'already_scheduled'], $this->reasons($this->plan($target, $this->now->addHours(5))));
        $this->assertSame(2, SeoRankCheckRun::query()->count());

        // The next UTC day is a new bucket.
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target, $this->now->addDay())));
        $this->assertSame(4, SeoRankCheckRun::query()->count());
    }

    public function test_a_not_yet_due_target_is_not_planned(): void
    {
        [, , , $target] = $this->trackedTarget();
        $target->forceFill(['next_check_at' => $this->now->addHour()])->save();

        $decisions = app(SeoRankCheckPlanner::class)->planScheduled($target->fresh(['business']), $this->now);

        $this->assertSame([], $decisions);
        $this->assertNothingCreated();
    }

    // ---------------------------------------------------------------
    // Pending + manual cooldown
    // ---------------------------------------------------------------

    public function test_a_check_that_is_still_pending_is_not_duplicated_by_the_next_window(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));

        // The runs are still scheduled/in flight when the next day's window opens.
        $next = $this->plan($target, $this->now->addDay());

        $this->assertSame(['pending', 'pending'], $this->reasons($next));
        $this->assertNotNull($next[0]->run, 'A pending refusal carries the run that already exists.');
        $this->assertSame(2, SeoRankCheckRun::query()->count());
        $this->assertSame(2, SeoRankProviderLedger::query()->count());
    }

    public function test_a_manual_refresh_is_refused_while_a_completed_observation_is_under_24_hours_old(): void
    {
        [$owner, , , $target] = $this->trackedTarget();
        $this->observe($target, $this->now->subHours(2));

        $decisions = $this->manual($target, (int) $owner->user_id);

        $this->assertSame(['recent_result', 'recent_result'], $this->reasons($decisions));
        $this->assertSame(1, SeoRankCheckRun::query()->count(), 'Only the observation fixture run exists.');
    }

    public function test_a_manual_refresh_is_allowed_once_the_latest_observation_is_older_than_24_hours(): void
    {
        [$owner, , , $target] = $this->trackedTarget();
        $this->observe($target, $this->now->subHours(25));

        $decisions = $this->manual($target, (int) $owner->user_id);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($decisions));
        $this->assertSame((int) $owner->user_id, (int) $decisions[0]->run->requested_by_user_id);
        $this->assertSame('manual', $decisions[0]->run->trigger->value ?? $decisions[0]->run->trigger);
    }

    public function test_a_manual_cooldown_applies_after_a_prior_manual_run_and_lifts_after_24_hours(): void
    {
        [$owner, , , $target] = $this->trackedTarget();
        $userId = (int) $owner->user_id;

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->manual($target, $userId)));
        SeoRankCheckRun::query()->update(['state' => 'completed', 'created_at' => $this->now]);

        // 14 hours later (next UTC day: a fresh idempotency key) -> cooldown, no observation needed.
        $later = $this->manual($target, $userId, $this->now->addHours(14));
        $this->assertSame(['cooldown', 'cooldown'], $this->reasons($later));
        $this->assertSame(2, SeoRankCheckRun::query()->count());

        // 25 hours after the first manual run it is allowed again.
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->manual($target, $userId, $this->now->addHours(25))));
    }

    public function test_a_released_manual_run_does_not_trigger_the_cooldown(): void
    {
        [$owner, , , $target] = $this->trackedTarget();
        $userId = (int) $owner->user_id;

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->manual($target, $userId)));
        SeoRankCheckRun::query()->update(['state' => 'failed_terminal', 'created_at' => $this->now]);
        SeoRankProviderLedger::query()->update(['status' => SeoRankProviderLedger::RELEASED]);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->manual($target, $userId, $this->now->addHours(14))));
    }

    public function test_scheduled_checks_ignore_the_manual_cooldown_and_recent_results(): void
    {
        [, , , $target] = $this->trackedTarget();
        $this->observe($target, $this->now->subHours(1));

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
    }

    // ---------------------------------------------------------------
    // Global circuit breaker
    // ---------------------------------------------------------------

    public function test_the_master_switch_off_refuses_everything_and_creates_nothing(): void
    {
        [$owner, , , $target] = $this->trackedTarget();
        config(['seo.rank_tracking.enabled' => false]);

        $this->assertSame(['disabled', 'disabled'], $this->reasons($this->plan($target)));
        $this->assertSame(['disabled', 'disabled'], $this->reasons($this->manual($target, (int) $owner->user_id)));
        $this->assertNothingCreated();
        $this->assertTrue(app(SeoRankTrackingBudget::class)->isPausedBySpend($target->business));
    }

    public function test_a_malformed_master_switch_is_off(): void
    {
        [, , , $target] = $this->trackedTarget();
        config(['seo.rank_tracking.enabled' => 'yes']);

        $this->assertSame(['disabled', 'disabled'], $this->reasons($this->plan($target)));
        $this->assertNothingCreated();
    }

    public function test_the_global_daily_cap_refuses_new_reservations(): void
    {
        [, , , $target] = $this->trackedTarget();
        config(['seo.rank_tracking.global_daily_cap_micros' => 1]);

        $decisions = $this->plan($target);

        $this->assertSame(['global_daily_cap', 'global_daily_cap'], $this->reasons($decisions));
        $this->assertTrue($decisions[0]->isBudgetPause());
        $this->assertNothingCreated();
    }

    public function test_the_global_daily_cap_counts_every_business_spend_of_the_day(): void
    {
        [, , , $first] = $this->trackedTarget(WorkspacePlanTier::Growth, 'first-booth.com');
        [, , , $second] = $this->trackedTarget(WorkspacePlanTier::Growth, 'second-booth.com');
        config(['seo.rank_tracking.global_daily_cap_micros' => self::PASS]);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($first)));
        $this->assertSame(['global_daily_cap', 'global_daily_cap'], $this->reasons($this->plan($second)));
        $this->assertSame(2, SeoRankCheckRun::query()->count());
    }

    public function test_the_global_monthly_cap_refuses_new_reservations(): void
    {
        [, , , $target] = $this->trackedTarget();
        config(['seo.rank_tracking.global_monthly_cap_micros' => 1]);

        $this->assertSame(['global_monthly_cap', 'global_monthly_cap'], $this->reasons($this->plan($target)));
        $this->assertNothingCreated();
    }

    // ---------------------------------------------------------------
    // Workspace / Agency aggregate cap
    // ---------------------------------------------------------------

    public function test_the_workspace_aggregate_cap_refuses_when_exhausted(): void
    {
        [, , , $target] = $this->trackedTarget();
        config(['seo.rank_tracking.workspace_monthly_cap_micros' => 1]);

        $this->assertSame(['workspace_cap', 'workspace_cap'], $this->reasons($this->plan($target)));
        $this->assertNothingCreated();
    }

    public function test_an_agency_client_follows_its_own_plan_while_the_agency_workspace_owns_the_aggregate_cap(): void
    {
        [$agencyOwner, $agencyBusiness, $agencyWorkspace] = $this->rankTenant(WorkspacePlanTier::Agency, 'agency-site.com');
        [, $coreBusiness, $coreWorkspace, $coreTarget] = $this->trackedTarget(WorkspacePlanTier::Core, 'client-core.com');
        [, $growthBusiness, $growthWorkspace, $growthTarget] = $this->trackedTarget(WorkspacePlanTier::Growth, 'client-growth.com');

        foreach ([$coreWorkspace, $growthWorkspace] as $client) {
            DB::table('agency_client_workspace_relationships')->insert([
                'uid' => (string) Str::uuid(),
                'agency_workspace_id' => $agencyWorkspace->id,
                'client_workspace_id' => $client->id,
                'status' => 'active',
                'established_by_user_id' => $agencyOwner->user_id,
                'established_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $entitlement = app(SeoRankEntitlement::class);
        $corePlan = $entitlement->planFor($coreBusiness->fresh(), $this->now);
        $growthPlan = $entitlement->planFor($growthBusiness->fresh(), $this->now);

        // Each client follows ITS OWN plan ...
        $this->assertSame('core', $corePlan->tier);
        $this->assertSame(1_500_000, $corePlan->monthlyCapMicros);
        $this->assertSame('growth', $growthPlan->tier);
        $this->assertSame(4_500_000, $growthPlan->monthlyCapMicros);
        // ... while the aggregate is owned by the Agency Workspace.
        $this->assertSame((int) $agencyWorkspace->id, $corePlan->budgetWorkspaceId);
        $this->assertSame((int) $agencyWorkspace->id, $growthPlan->budgetWorkspaceId);

        // The aggregate holds exactly one pass.
        config(['seo.rank_tracking.workspace_monthly_cap_micros' => self::PASS]);

        $first = $this->plan($coreTarget);
        $this->assertSame(['allowed', 'allowed'], $this->reasons($first));
        $this->assertSame((int) $agencyWorkspace->id, (int) $first[0]->run->workspace_id);
        $this->assertSame((int) $agencyWorkspace->id, (int) SeoRankProviderLedger::query()->value('workspace_id'));

        // A second client of the same Agency is refused although its OWN plan has plenty of room.
        $second = $this->plan($growthTarget);
        $this->assertSame(['workspace_cap', 'workspace_cap'], $this->reasons($second));
        $this->assertSame(0, SeoRankCheckRun::query()->where('business_id', $growthBusiness->id)->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_a_terminated_agency_relationship_does_not_share_the_aggregate(): void
    {
        [$agencyOwner, , $agencyWorkspace] = $this->rankTenant(WorkspacePlanTier::Agency, 'agency-site.com');
        [, , $clientWorkspace, $clientTarget] = $this->trackedTarget(WorkspacePlanTier::Growth, 'client-growth.com');

        DB::table('agency_client_workspace_relationships')->insert([
            'uid' => (string) Str::uuid(),
            'agency_workspace_id' => $agencyWorkspace->id,
            'client_workspace_id' => $clientWorkspace->id,
            'status' => 'terminated',
            'established_by_user_id' => $agencyOwner->user_id,
            'established_at' => now(),
            'terminated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(SeoRankEntitlement::class)->planFor($clientTarget->business()->first(), $this->now);

        $this->assertSame((int) $clientWorkspace->id, $plan->budgetWorkspaceId);
    }

    public function test_the_agency_tier_uses_growth_limits(): void
    {
        [, $business] = $this->rankTenant(WorkspacePlanTier::Agency);

        $plan = app(SeoRankEntitlement::class)->planFor($business, $this->now);

        $this->assertNotNull($plan);
        $this->assertSame('growth', $plan->tier);
        $this->assertFalse($plan->isTrial);
        $this->assertSame(20, $plan->trackedTargets);
        $this->assertSame(1, $plan->cadenceDays);
        $this->assertSame(4_500_000, $plan->monthlyCapMicros);
        $this->assertSame($this->now->startOfMonth()->toIso8601String(), $plan->capPeriodStart->toIso8601String());
    }

    // ---------------------------------------------------------------
    // Slots, entitlement, inactive targets
    // ---------------------------------------------------------------

    public function test_a_target_beyond_the_slot_allowance_is_never_checked(): void
    {
        [$owner, $business, , $first, $keyword] = $this->trackedTarget(WorkspacePlanTier::Growth);
        $second = $this->track($owner, $business, $this->keyword($owner, $business, 'wedding photo booth'));

        // A "downgrade": the plan now allows a single tracked target.
        config(['seo.rank_tracking.tiers.growth.tracked_targets' => 1]);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($first)), 'The oldest target keeps the slot.');
        $this->assertSame(['over_slot_limit', 'over_slot_limit'], $this->reasons($this->plan($second)));
        $this->assertSame(0, SeoRankCheckRun::query()->where('seo_rank_target_id', $second->id)->count());
        $this->assertSame(2, SeoRankProviderLedger::query()->count());
    }

    public function test_a_workspace_without_a_plan_is_not_entitled_and_nothing_is_created(): void
    {
        [, , $workspace, $target] = $this->trackedTarget();
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->delete();
        app(RequestScopedCache::class)->flush();

        // The planner stays quiet; the budget authority itself refuses.
        $this->assertSame([], $this->plan($target));

        $decision = app(SeoRankTrackingBudget::class)->reserveRun(
            $target,
            SeoRankCheckType::Organic,
            SeoRankTrigger::Scheduled,
            $target->id . ':organic:s1',
            null,
            $this->now,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(SeoRankBudgetDecision::NOT_ENTITLED, $decision->reason);
        $this->assertNothingCreated();
    }

    public function test_a_stopped_target_is_target_inactive(): void
    {
        [$owner, $business, , $target] = $this->trackedTarget();
        app(SeoRankTargetManager::class)->stop((int) $owner->user_id, $business, $target->uid);

        $decision = app(SeoRankTrackingBudget::class)->reserveRun(
            $target->fresh(),
            SeoRankCheckType::Organic,
            SeoRankTrigger::Scheduled,
            $target->id . ':organic:s1',
            null,
            $this->now,
        );

        $this->assertSame(SeoRankBudgetDecision::TARGET_INACTIVE, $decision->reason);
        $this->assertNothingCreated();
    }

    public function test_an_archived_keyword_makes_its_target_inactive(): void
    {
        [$owner, $business, , $target, $keyword] = $this->trackedTarget();
        app(SeoKeywordManager::class)->archive((int) $owner->user_id, $business, $keyword);

        $decision = app(SeoRankTrackingBudget::class)->reserveRun(
            $target->fresh(),
            SeoRankCheckType::Local,
            SeoRankTrigger::Manual,
            $target->id . ':local:m1',
            (int) $owner->user_id,
            $this->now,
        );

        $this->assertSame(SeoRankBudgetDecision::TARGET_INACTIVE, $decision->reason);
        $this->assertNothingCreated();
    }

    // ---------------------------------------------------------------
    // Ledger accounting
    // ---------------------------------------------------------------

    public function test_released_ledger_rows_count_zero_toward_every_cap(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);
        $this->spend($target, 1_500_000, SeoRankProviderLedger::RELEASED);

        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
    }

    public function test_held_ledger_rows_keep_counting(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);
        $this->spend($target, 1_500_000, SeoRankProviderLedger::HELD);

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($this->plan($target)));
    }

    public function test_reserved_ledger_rows_keep_counting(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);
        $this->spend($target, 1_500_000, SeoRankProviderLedger::RESERVED);

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($this->plan($target)));
    }

    public function test_a_committed_row_counts_its_actual_cost_not_its_reservation(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        // Reserved the whole cap, but the provider only charged 1_000_000: there is room.
        $this->spend($target, 1_500_000, SeoRankProviderLedger::COMMITTED, 1_000_000);
        $this->assertSame(['allowed', 'allowed'], $this->reasons($this->plan($target)));
    }

    public function test_a_committed_row_whose_actual_cost_exceeds_its_reservation_counts_the_actual(): void
    {
        [, , , $target] = $this->trackedTarget(WorkspacePlanTier::Core);

        $this->spend($target, 100, SeoRankProviderLedger::COMMITTED, 1_500_000);

        $this->assertSame(['business_cap', 'business_cap'], $this->reasons($this->plan($target)));
    }

    public function test_is_paused_by_spend_reflects_the_business_cap(): void
    {
        [, $business, , $target] = $this->trackedTarget(WorkspacePlanTier::Core);
        $budget = app(SeoRankTrackingBudget::class);

        $this->assertFalse($budget->isPausedBySpend($business->fresh(), $this->now));

        $this->spend($target, 1_500_000);

        $this->assertTrue($budget->isPausedBySpend($business->fresh(), $this->now));
    }

    public function test_the_same_period_key_never_creates_a_second_run_even_when_called_directly(): void
    {
        [, , , $target] = $this->trackedTarget();
        $budget = app(SeoRankTrackingBudget::class);
        $key = $target->id . ':organic:s123';

        $first = $budget->reserveRun($target, SeoRankCheckType::Organic, SeoRankTrigger::Scheduled, $key, null, $this->now);
        $second = $budget->reserveRun($target, SeoRankCheckType::Organic, SeoRankTrigger::Scheduled, $key, null, $this->now);

        $this->assertTrue($first->allowed);
        $this->assertFalse($second->allowed);
        $this->assertSame(SeoRankBudgetDecision::ALREADY_SCHEDULED, $second->reason);
        $this->assertSame($first->run->id, $second->run->id);
        $this->assertSame(1, SeoRankCheckRun::query()->count());
        $this->assertSame(1, SeoRankProviderLedger::query()->count());
    }
}
