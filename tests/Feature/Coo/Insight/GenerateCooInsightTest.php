<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Coo\GenerateCooInsight;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Coo\Insight\CooInsightGenerator;
use App\Library\Coo\Insight\CooInsightOutcome;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\CooInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * AI-3 — GenerateCooInsight through the real AI-1 gateway (only the provider is
 * faked): §8.1 refusals spend nothing, §8.2 E-1…E-4 fire only under their
 * conditions (T-COO-4/5), identical facts are never paid twice (T-INS-1), the
 * prompt carries no contact or message-level data (T-INS-5), and a rejected
 * output caches nothing (T-INS-4).
 */
class GenerateCooInsightTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    // =================================================================
    // E-1 through the gateway
    // =================================================================

    public function test_e1_buys_one_insight_through_the_gateway_and_caches_the_validated_output(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);

        $outcome = $this->generate($business, CooInsightTrigger::MultiSignalChange);

        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status);
        $this->assertSame(1, $this->fakeAi->callCount());

        $insight = CooInsight::query()->sole();
        $entry = AiUsageLedgerEntry::query()->sole();

        $this->assertSame(CooInsightKind::PerformanceDiagnosis, $insight->kind);
        $this->assertSame([CooInsight::SUBJECT_BUSINESS, (int) $business->id], [$insight->subject_type, $insight->subject_id]);
        $this->assertSame('this_month_2026-09', $insight->period_key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $insight->signal_fingerprint);
        $this->assertCount(3, $insight->output['statements']);
        $this->assertSame((int) config('coo.insight.prompt_version'), $insight->prompt_version);
        $this->assertSame('routine', $insight->model_route->value);
        $this->assertSame('fixture-model-2026', $insight->provider_model);
        $this->assertSame((int) $entry->id, $insight->ai_usage_ledger_entry_id, 'The ledger carries the cost; the insight points at it.');
        $this->assertTrue($insight->expires_at->equalTo($insight->generated_at->copy()->addDays(7)), 'TTL is config coo.insight_ttl_days.');

        $this->assertSame(AiUsageCategory::CooDiagnosis, $entry->category);
        $this->assertSame(AiLane::Product, $entry->lane);
        $this->assertStringStartsWith('coo_insight:', $entry->idempotency_key, 'A key derived from the insight identity, never a random one.');
        $this->assertStringEndsWith(':1', $entry->idempotency_key);

        $facts = collect($insight->facts_snapshot['facts'])->keyBy('ref');
        $this->assertSame(20, $facts['metric.new_contacts']['current_period']);
        $this->assertSame('material_increase', $facts['metric.new_contacts']['classification']);
    }

    public function test_identical_or_merely_nearby_facts_reuse_the_row_and_never_pay_twice(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);

        $this->generate($business, CooInsightTrigger::MultiSignalChange);
        $this->assertSame(CooInsightOutcome::ALREADY_CACHED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);

        // One more contact: 21 is still in the 10–24 band, still material.
        $this->contactsAdded($business, 1, '2026-09-07');
        $this->assertSame(CooInsightOutcome::ALREADY_CACHED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);
        $this->assertSame(CooInsightOutcome::ALREADY_CACHED, $this->generate($business, CooInsightTrigger::ExplainThisChange)->status, 'Even an explicit ask does not pay for identical facts.');

        $this->assertSame(1, $this->fakeAi->callCount());
        $this->assertSame(1, AiUsageLedgerEntry::query()->count());
        $this->assertSame(1, CooInsight::query()->count());
    }

    public function test_e1_needs_at_least_two_material_metrics(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->contactsAdded($business, 20, '2026-09-05');
        $this->contactsAdded($business, 5, '2026-08-25');

        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);
        $this->assertNothingSpent();
    }

    public function test_e1_is_not_fired_when_a_deterministic_rule_is_present(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->recommendation($business, ['type' => 'missing_phone', 'first_detected_at' => Carbon::now()->subDays(2)]);

        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status, 'An Opportunity first detected in the period may explain it.');

        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Attention Venue', 'Attention Account');
        $this->materialPeriod($other);
        $this->automationRuns($other, 2, '2026-09-04', 'failed');

        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $this->generate($other, CooInsightTrigger::MultiSignalChange)->status, 'A raised Attention type may explain it.');
        $this->assertNothingSpent();
    }

    public function test_an_opportunity_from_before_the_period_does_not_block_e1(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->recommendation($business, ['type' => 'missing_phone', 'first_detected_at' => Carbon::parse('2026-07-01 12:00:00')]);

        $this->assertSame(CooInsightOutcome::GENERATED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);
    }

    // =================================================================
    // E-2, E-3, E-4
    // =================================================================

    public function test_e2_fires_only_while_e1_still_holds(): void
    {
        [, $quiet] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        $this->contactsAdded($quiet, 3, '2026-09-05');
        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $this->generate($quiet, CooInsightTrigger::WorkFinished)->status);
        $this->assertSame(0, $this->fakeAi->callCount());

        [, $moving] = $this->tenant(WorkspacePlanTier::Growth, 'Moving Venue', 'Moving Account');
        $this->materialPeriod($moving);
        $this->assertSame(CooInsightOutcome::GENERATED, $this->generate($moving, CooInsightTrigger::WorkFinished)->status);
    }

    public function test_e3_monthly_pays_only_when_the_fingerprint_moved_since_the_last_insight(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->contactsAdded($business, 3, '2026-09-05');
        $this->fakeAi->setDefaultResult($this->neutralAnswer());

        $this->assertSame(CooInsightOutcome::GENERATED, $this->generate($business, CooInsightTrigger::MonthlyReview)->status, 'No insight yet: the fingerprint differs from nothing.');
        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $this->generate($business, CooInsightTrigger::MonthlyReview)->status, 'Unchanged fingerprint: no call (T-COO-5).');
        $this->assertSame(1, $this->fakeAi->callCount());

        $first = CooInsight::query()->sole();

        $this->materialPeriod($business);
        $this->assertSame(CooInsightOutcome::GENERATED, $this->generate($business, CooInsightTrigger::MonthlyReview)->status);
        $this->assertSame(2, $this->fakeAi->callCount());

        $first->refresh();
        $this->assertSame(CooInsightInvalidationReason::SignalChanged, $first->invalidation_reason, 'The next signal read retired the old row…');
        $this->assertSame(2, CooInsight::query()->count(), '…without deleting it.');
    }

    public function test_e4_is_charged_to_the_interactive_lane_and_a_customer_ask_is_not_refused_for_dormancy(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        // Nothing at all in 30 days: a dormant Business.

        $this->assertSame(CooInsightOutcome::DORMANT, $this->generate($business, CooInsightTrigger::MonthlyReview)->status);
        $this->fakeAi->setDefaultResult($this->neutralAnswer());

        $outcome = $this->generate($business, CooInsightTrigger::ExplainThisChange, actorUserId: (int) $business->customer->user_id);

        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status);

        $entry = AiUsageLedgerEntry::query()->sole();
        $this->assertSame(AiLane::Interactive, $entry->lane);
        $this->assertSame(AiUsageCategory::CooInteractive, $entry->category);
        $this->assertSame((int) $business->customer->user_id, (int) $entry->actor_user_id);
    }

    // =================================================================
    // §8.1 — the refusals that spend nothing
    // =================================================================

    public function test_a_dormant_business_fires_no_scheduled_trigger_and_the_ledger_stays_empty(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([CooInsightTrigger::MultiSignalChange, CooInsightTrigger::WorkFinished, CooInsightTrigger::MonthlyReview] as $trigger) {
            $this->assertSame(CooInsightOutcome::DORMANT, $this->generate($business, $trigger)->status, $trigger->value);
        }

        $this->assertNothingSpent();
    }

    public function test_ai_switched_off_reads_nothing_and_spends_nothing(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        config(['services.openai.active' => false]);

        foreach (CooInsightTrigger::cases() as $trigger) {
            $this->assertSame(CooInsightOutcome::AI_DISABLED, $this->generate($business, $trigger)->status);
        }

        $this->assertNothingSpent();
    }

    public function test_denied_inactive_suspended_and_unassigned_plans_spend_nothing(): void
    {
        [, $denied, $deniedWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Denied Venue', 'Denied Account');
        $this->materialPeriod($denied);
        app(EntitlementManager::class)->createOrChangeOverride($deniedWorkspace, PlatformFeature::AiCooBasic, WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'AI-3 fixture.');

        [, $suspended, $suspendedWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Suspended Venue', 'Suspended Account');
        $this->materialPeriod($suspended);
        app(EntitlementManager::class)->changePlanStatus($suspendedWorkspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'AI-3 fixture.');

        [, $inactive, $inactiveWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Inactive Venue', 'Inactive Account');
        $this->materialPeriod($inactive);
        app(EntitlementManager::class)->changePlanStatus($inactiveWorkspace, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'AI-3 fixture.');

        $this->ensureRequiredAppConfigRowsExist();
        $owner = $this->createCustomer();
        $unassignedWorkspace = $this->createWorkspace($owner->user, ['name' => 'Unassigned Account']);
        $unassigned = $this->addBusiness($owner, $unassignedWorkspace, 'Unassigned Venue');
        $this->materialPeriod($unassigned);

        foreach ([$denied, $suspended, $inactive, $unassigned] as $business) {
            foreach (CooInsightTrigger::cases() as $trigger) {
                $this->assertSame(CooInsightOutcome::NOT_ENTITLED, $this->generate($business->fresh(), $trigger)->status, $business->name . ' / ' . $trigger->value);
            }
        }

        $this->assertNothingSpent();
    }

    // =================================================================
    // §11.4 exhaustion, provider failure, rejected output
    // =================================================================

    public function test_budget_exhausted_skips_without_a_provider_call_or_a_retry(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->exhaustBudget($workspace);

        $outcome = $this->generate($business, CooInsightTrigger::MultiSignalChange);

        $this->assertSame(CooInsightOutcome::REFUSED, $outcome->status);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $outcome->refusalReason);
        $this->assertSame(0, $this->fakeAi->callCount());
        $this->assertSame(0, CooInsight::query()->count());
        $this->assertSame(1, (new GenerateCooInsight((int) $business->id, CooInsightTrigger::MultiSignalChange->value))->tries, 'The job runs once: no retry loop.');
    }

    public function test_budget_exhaustion_does_not_touch_an_insight_already_cached(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->generate($business, CooInsightTrigger::MultiSignalChange);
        $this->exhaustBudget($workspace);

        $this->assertSame(CooInsightOutcome::ALREADY_CACHED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);

        $insight = CooInsight::query()->sole();
        $this->assertNull($insight->invalidated_at, 'Being unable to regenerate never retires a valid answer.');
    }

    public function test_after_an_unpaid_refusal_the_next_attempt_gets_a_fresh_derived_key(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->exhaustBudget($workspace);

        $this->generate($business, CooInsightTrigger::MultiSignalChange);
        $refused = AiUsageLedgerEntry::query()->sole();
        $this->assertStringEndsWith(':1', $refused->idempotency_key);

        DB::table('ai_usage_periods')->delete();
        $this->assertSame(CooInsightOutcome::GENERATED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);

        $paid = AiUsageLedgerEntry::query()->where('id', '!=', $refused->id)->sole();
        $this->assertSame(substr($refused->idempotency_key, 0, -2) . ':2', $paid->idempotency_key, 'Same identity, next attempt.');
    }

    public function test_a_provider_failure_caches_nothing(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->fakeAi->setDefaultResult(AiCompletionResult::failure());

        $this->assertSame(CooInsightOutcome::PROVIDER_FAILED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);
        $this->assertSame(0, CooInsight::query()->count());
    }

    public function test_an_invalid_output_is_discarded_and_nothing_is_cached(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->fakeAi->setDefaultResult(AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'known', 'text' => 'The new website drove 20 new contacts.', 'fact_refs' => ['metric.new_contacts']],
        ]]), 'fixture-model-2026', 400, 60));

        $this->assertSame(CooInsightOutcome::OUTPUT_REJECTED, $this->generate($business, CooInsightTrigger::MultiSignalChange)->status);
        $this->assertSame(0, CooInsight::query()->count(), 'A rejected output renders nothing because nothing is stored.');
        $this->assertSame(1, AiUsageLedgerEntry::query()->count(), 'The cost is still on the ledger.');
    }

    // =================================================================
    // T-INS-5 — privacy
    // =================================================================

    public function test_the_prompt_carries_no_name_phone_email_or_message_body(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Zephyrine Private Studio', 'Zephyrine Account');
        $this->materialPeriod($business);

        $group = $this->contactGroup($business);
        $contact = \App\Models\Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $business->id, 'group_id' => $group->id,
            'phone' => '13035559876', 'status' => \App\Models\Contacts::STATUS_SUBSCRIBE,
            'first_name' => 'Quintessa', 'last_name' => 'Marvelwood', 'email' => 'quintessa.private@example.test',
        ]);
        DB::table('contacts')->where('id', $contact->id)->update(['created_at' => $this->localNoon('2026-09-03', $business->timezone)]);

        DB::table('reports')->where('business_id', $business->id)->where('direction', 'incoming')->limit(1)
            ->update(['message' => 'My gate code is 48151623, please do not share', 'from' => '14155550142']);

        $boxId = $this->conversationWith($business, [['incoming', $this->localNoon('2026-09-04', $business->timezone)]]);
        DB::table('chat_box_messages')->where('box_id', $boxId)->update(['message' => 'Call me on 415 555 0142 about the booking']);

        $this->recommendation($business, [
            'type' => 'missing_email',
            'first_detected_at' => Carbon::parse('2026-07-01 12:00:00'),
            'evidence' => json_encode([['source_type' => 'business_profile', 'fact_key' => 'email_blank', 'observed_value' => 'owner.secret@example.test', 'summary' => 'Owner secret summary text']]),
        ]);

        $this->generate($business, CooInsightTrigger::ExplainThisChange, actorUserId: (int) $customer->user_id);

        $this->assertSame(1, $this->fakeAi->callCount());
        $prompt = implode("\n", array_map(fn (array $m): string => (string) $m['content'], $this->fakeAi->requests()[0]->messages));

        foreach (['Quintessa', 'Marvelwood', 'quintessa.private', '@example.test', '13035559876', '14155550142', '415 555 0142', '48151623', 'gate code', 'booking', 'Zephyrine', 'owner.secret', 'Owner secret summary'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $prompt, "The prompt must not carry '{$forbidden}'.");
        }

        $this->assertStringContainsString('metric.new_contacts', $prompt, 'It does carry the aggregate facts.');
        $this->assertStringContainsString('The business email address is not set.', $prompt, 'And the registry\'s own static evidence wording.');
    }

    public function test_the_job_runs_the_generator_for_its_trigger_and_window(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);

        (new GenerateCooInsight((int) $business->id, CooInsightTrigger::MultiSignalChange->value, ['range' => 'this_month']))->handle(app(CooInsightGenerator::class));
        (new GenerateCooInsight((int) $business->id, 'not-a-trigger'))->handle(app(CooInsightGenerator::class));
        (new GenerateCooInsight(999999, CooInsightTrigger::MultiSignalChange->value))->handle(app(CooInsightGenerator::class));

        $this->assertSame(1, CooInsight::query()->count());
        $this->assertSame(1, $this->fakeAi->callCount());
    }

    public function test_a_plan_suspended_between_two_worker_jobs_denies_the_second_insight_and_spends_nothing(): void
    {
        // Contract 13: one Workspace holds exactly one Business, so the two
        // jobs are two jobs for THAT Business — which is what this test needs
        // anyway. The defect it guards is a plan read memoized by job 1 and
        // reused by job 2 in the same worker process, and the memo is keyed
        // by Workspace, so both jobs must share one Workspace to exercise it.
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'First Venue', 'Shared Account');
        $this->materialPeriod($business);

        // At each provider call, record whether this worker process holds the plan read memoized.
        $planMemoKey = 'workspace_plan_assignment:find:' . $workspace->id;
        $memoizedAtProviderCall = [];
        $this->app->instance(AiCompletionClient::class, new class ($this->fakeAi, function () use (&$memoizedAtProviderCall, $planMemoKey): void {
            $memoizedAtProviderCall[] = app(RequestScopedCache::class)->has($planMemoKey);
        }) implements AiCompletionClient {
            public function __construct(private readonly FakeAiCompletionClient $fake, private readonly \Closure $onCall)
            {
            }

            public function complete(AiCompletionRequest $request): AiCompletionResult
            {
                ($this->onCall)();

                return $this->fake->complete($request);
            }
        });

        // JOB 1 — plan active: ai_coo_basic allowed, the plan read memoized, one insight bought.
        $this->pushInsightJob($business);
        $this->workOneInsightJob();

        $this->assertSame(1, $this->fakeAi->callCount(), 'Precondition: job 1 was entitled and paid.');
        $this->assertSame([true], $memoizedAtProviderCall, 'Precondition: job 1 memoized the plan read in this worker process.');
        $ledgerAfterJobOne = AiUsageLedgerEntry::query()->count();
        $insightsAfterJobOne = CooInsight::query()->pluck('id')->all();

        // New signals arrive, so jobs 2 and 3 ask for a genuinely new insight
        // rather than being served job 1's cached one: the generator dedups on
        // the facts fingerprint, and reusing job 1's facts would skip job 2
        // for that reason instead of the entitlement one under test.
        $this->contactsAdded($business, 9, '2026-09-08');

        // Between jobs — suspended by another process, not through this worker's repository.
        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['status' => WorkspacePlanAssignmentStatus::Suspended->value]));

        // JOB 2 — same worker, container and console request; nothing in the job or the test clears the memo.
        $this->pushInsightJob($business);
        $this->workOneInsightJob();

        $this->assertSame(1, $this->fakeAi->callCount(), 'Job 2 made no provider call.');
        $this->assertSame($ledgerAfterJobOne, AiUsageLedgerEntry::query()->count(), 'Job 2 reserved nothing and wrote no ledger row.');
        $this->assertSame(0, CooInsight::query()->whereNotIn('id', $insightsAfterJobOne)->count(), 'Job 2 cached no insight.');

        // JOB 3 — reactivated the same way: the same Business, same facts, now pays. Job 2's refusal was entitlement alone.
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['status' => WorkspacePlanAssignmentStatus::Active->value]);
        $this->pushInsightJob($business);
        $this->workOneInsightJob();

        $this->assertSame(2, $this->fakeAi->callCount(), 'The next job sees the reactivation, too.');
        $this->assertSame(1, CooInsight::query()->whereNotIn('id', $insightsAfterJobOne)->count(), 'Job 3 cached exactly the insight job 2 was refused.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Every job was processed by the worker.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'None failed.');
    }

    // -----------------------------------------------------------------

    private function generate(Business $business, CooInsightTrigger $trigger, ?int $actorUserId = null): CooInsightOutcome
    {
        return app(CooInsightGenerator::class)->generate($business->fresh(), $trigger, $this->thisMonth($business), $actorUserId);
    }

    private function pushInsightJob(Business $business): void
    {
        Queue::connection('database')->push(new GenerateCooInsight((int) $business->id, CooInsightTrigger::MultiSignalChange->value, ['range' => 'this_month']));
    }

    /** One job, processed by Laravel's real worker, in this process — as `queue:work` would. */
    private function workOneInsightJob(): void
    {
        app('queue.worker')->runNextJob('database', (string) config('coo.insight.queue', 'default'), new WorkerOptions(sleep: 0, maxTries: 1));
    }

    private function assertNothingSpent(): void
    {
        $this->assertSame(0, $this->fakeAi->callCount(), 'No provider call.');
        $this->assertSame(0, AiUsageLedgerEntry::query()->count(), 'No reservation, no ledger row.');
        $this->assertSame(0, CooInsight::query()->count(), 'No insight.');
    }

    /** A valid answer for any facts: it claims nothing a number could contradict. */
    private function neutralAnswer(): AiCompletionResult
    {
        return AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'unknown', 'text' => 'These figures alone do not show why they changed.', 'fact_refs' => []],
        ]]), 'fixture-model-2026', 300, 30);
    }

    private function exhaustBudget(\App\Models\Workspace $workspace): void
    {
        DB::table('ai_usage_periods')->updateOrInsert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'period_key' => Carbon::now('UTC')->format('Y-m'),
        ], [
            'workspace_id' => $workspace->id, 'policy_key' => 'growth', 'policy_version' => 1,
            'cap_microusd' => 1, 'reserved_microusd' => 0, 'committed_microusd' => 1,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 0,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }
}
