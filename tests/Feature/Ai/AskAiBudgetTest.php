<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiAllowance;
use App\Library\Ai\AiAllowanceReader;
use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsagePresenter;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageState;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Ask MotionGrove — the AI allowances, enforced. Trial = $3.00 for the whole trial (once, per Workspace); Core $5,
 * Growth $10, Agency $25 / $6 per Business — all micro-USD, all HARD for every category, and the paid allowance only
 * ever follows a provider-confirmed conversion. No provider is reached: the fake client answers.
 */
class AskAiBudgetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        config(['services.openai.active' => true]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: Business} */
    private function account(WorkspacePlanTier $tier, ?\DateTimeInterface $trialEndsAt = null): array
    {
        [, $business, $workspace] = $this->tenant($tier, 'Budget Venue ' . Str::random(4), 'Budget Account ' . Str::random(4));

        if ($trialEndsAt !== null) {
            $this->assignment($workspace, ['trial_ends_at' => $trialEndsAt, 'is_complimentary' => false]);
        }

        return [$workspace->fresh(), $business->fresh()];
    }

    /** Raw lifecycle edit + drop the request-scoped assignment memo, as the next real request would see it. */
    private function assignment(Workspace $workspace, array $columns): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update($columns);
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
    }

    private function policy(Workspace $workspace)
    {
        return app(AiBudgetPolicyResolver::class)->resolveFor($workspace);
    }

    private function request(Workspace $workspace, ?Business $business, AiUsageCategory $category = AiUsageCategory::AskAssistant, AiLane $lane = AiLane::Interactive, string $content = 'hello', ?string $key = null): AiRequest
    {
        return AiRequest::forWorkspace(
            $workspace, $business, $category, $lane, $category === AiUsageCategory::AskAssistant ? AiModelRoute::Assistant : AiModelRoute::Routine,
            [['role' => 'user', 'content' => $content]], 300, $key ?? (string) Str::uuid(), null,
        );
    }

    private function openPeriod(Workspace $workspace, string $key, string $policy, int $cap, int $committed = 0, int $interactiveCommitted = 0): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => $key,
            'policy_key' => $policy, 'policy_version' => 1, 'cap_microusd' => $cap, 'reserved_microusd' => 0,
            'committed_microusd' => $committed, 'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => $interactiveCommitted,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── The allowances ───────────────────────────────────────────────────

    public function test_a_trial_starts_with_a_three_dollar_allowance_for_the_whole_trial(): void
    {
        [$workspace] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));

        $policy = $this->policy($workspace);

        $this->assertSame('trial', $policy->policyKey);
        $this->assertSame(3_000_000, $policy->workspaceCapMicrousd);
        $this->assertSame('trial', $policy->periodKey);
        $this->assertSame(8000, $policy->interactiveShareBps);
        $this->assertNull($policy->businessCapMicrousd);
    }

    public function test_paid_monthly_allowances_and_the_interactive_share_are_as_specified(): void
    {
        foreach ([[WorkspacePlanTier::Core, 5_000_000, null], [WorkspacePlanTier::Growth, 10_000_000, null], [WorkspacePlanTier::Agency, 25_000_000, 6_000_000]] as [$tier, $workspaceCap, $businessCap]) {
            [$workspace] = $this->account($tier);
            $policy = $this->policy($workspace);

            $this->assertSame($workspaceCap, $policy->workspaceCapMicrousd, $tier->value);
            $this->assertSame($businessCap, $policy->businessCapMicrousd, $tier->value);
            $this->assertSame('2026-09', $policy->periodKey);
        }

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$workspace] = $this->account($tier);
            $this->assertSame(8000, $this->policy($workspace)->interactiveShareBps);
        }
    }

    public function test_the_trial_period_survives_the_calendar_month_boundary_and_never_resets(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, Carbon::parse('2026-10-10', 'UTC'));
        $gateway = app(AiGateway::class);

        $this->assertTrue($gateway->complete($this->request($workspace, $business))->ok);
        $before = AiUsagePeriod::query()->where('workspace_id', $workspace->id)->get();
        $this->assertSame(['trial'], $before->pluck('period_key')->unique()->all());

        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'UTC'));
        $this->assertSame('trial', $this->policy($workspace->fresh())->periodKey, 'October did not open a fresh allowance.');
        $this->assertTrue($gateway->complete($this->request($workspace, $business))->ok);

        $periods = AiUsagePeriod::query()->where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $periods, 'One trial period, however many months it spans.');
        $this->assertGreaterThan((int) $before->first()->committed_microusd, (int) $periods->first()->committed_microusd, 'Spend accumulated in the same period.');
    }

    public function test_repeated_loads_and_a_changed_trial_end_date_cannot_open_a_second_trial_allowance(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $gateway = app(AiGateway::class);
        $gateway->complete($this->request($workspace, $business));

        $this->assignment($workspace, ['trial_ends_at' => now()->addDays(40)]);
        $reader = app(AiAllowanceReader::class);
        $reader->forWorkspace($workspace->fresh(), $business);
        $reader->forWorkspace($workspace->fresh(), $business);
        $gateway->complete($this->request($workspace->fresh(), $business));

        $this->assertSame(1, AiUsagePeriod::query()->where('workspace_id', $workspace->id)->where('period_key', 'trial')->count());
    }

    // ── Hard cap ─────────────────────────────────────────────────────────

    public function test_a_request_is_refused_without_a_provider_call_when_it_does_not_fit_the_remaining_allowance(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 2_999_900);

        $result = app(AiGateway::class)->complete($this->request($workspace, $business));

        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
        $this->assertSame(0, $this->fake->callCount());
        $this->assertSame(2_999_900, (int) AiUsagePeriod::query()->where('workspace_id', $workspace->id)->value('committed_microusd'));
    }

    public function test_every_category_shares_the_trial_cap_even_with_observation_mode_off(): void
    {
        config(['ai.enforce_budgets_for_existing_categories' => false]);
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 3_000_000);

        foreach ([AiUsageCategory::WebsiteGeneration, AiUsageCategory::CampaignMessageDraft, AiUsageCategory::AgencyProspectReply, AiUsageCategory::ContentAutopilot, AiUsageCategory::AskAssistant] as $category) {
            $result = app(AiGateway::class)->complete($this->request($workspace, $business, $category, AiLane::Product));

            $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason, $category->value . ' must not spend past the trial cap.');
        }

        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_former_observation_categories_cannot_exceed_a_paid_cap_by_default(): void
    {
        config(['ai.enforce_budgets_for_existing_categories' => false]); // retired flag: must change nothing
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->openPeriod($workspace, '2026-09', 'core', 5_000_000, 5_000_000);

        $result = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, AiLane::Product));

        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_requests_accumulate_to_the_cap_and_never_past_it(): void
    {
        config(['ai.budgets.trial.workspace_cap_microusd' => 6_000]);
        // Each call really costs ~1,400 microusd (8,000 in / 300 out), so only a few fit.
        $this->fake->setDefaultResult(AiCompletionResult::success('{"ok":true}', 'gpt-4o-mini', 8_000, 300));
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $gateway = app(AiGateway::class);
        $accepted = 0;

        for ($i = 0; $i < 40; $i++) {
            $result = $gateway->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, AiLane::Product));
            $accepted += $result->ok ? 1 : 0;
        }

        $period = AiUsagePeriod::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertGreaterThan(0, $accepted);
        $this->assertLessThan(40, $accepted);
        $this->assertLessThanOrEqual(6_000, (int) $period->committed_microusd + (int) $period->reserved_microusd);
        $this->assertSame($accepted, $this->fake->callCount(), 'Only accepted requests reach the provider.');
    }

    // ── Interactive share vs total ───────────────────────────────────────

    public function test_interactive_share_exhaustion_is_distinguished_from_total_exhaustion(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        // 80% of $3.00 = $2.40 interactive; spend exactly that, with $0.60 left overall.
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 2_400_000, 2_400_000);
        $gateway = app(AiGateway::class);

        $interactive = $gateway->complete($this->request($workspace, $business, AiUsageCategory::AskAssistant, AiLane::Interactive));
        $this->assertSame(AiRefusalReason::InteractiveShareExhausted, $interactive->refusalReason);

        $reader = app(AiAllowanceReader::class)->forWorkspace($workspace, $business);
        $this->assertSame(AiAllowance::INTERACTIVE_LIMIT, $reader->status, 'Not "all AI is used up".');
        $this->assertFalse($reader->usable());

        // The protected product lane still has room.
        $product = $gateway->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, AiLane::Product));
        $this->assertTrue($product->ok);

        // Now spend the rest: the whole allowance is used up.
        DB::table('ai_usage_periods')->where('workspace_id', $workspace->id)->update(['committed_microusd' => 3_000_000]);
        $gateway->complete($this->request($workspace->fresh(), $business, AiUsageCategory::WebsiteGeneration, AiLane::Product));
        $this->assertSame(AiAllowance::TRIAL_LIMIT_REACHED, app(AiAllowanceReader::class)->forWorkspace($workspace->fresh(), $business)->status);
    }

    public function test_approaching_the_limit_at_the_configured_threshold_is_a_notice_not_a_block(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 2_400_000, 100_000);

        $state = app(AiAllowanceReader::class)->forWorkspace($workspace, $business);

        $this->assertSame(AiAllowance::NEARING_LIMIT, $state->status);
        $this->assertTrue($state->usable());
    }

    // ── Lifecycle: expiry, payment failure, conversion ───────────────────

    public function test_an_expired_unconverted_trial_gets_no_paid_ai_and_no_trial_ai(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->subDay());

        $this->assertSame(0, $this->policy($workspace)->workspaceCapMicrousd);
        $state = app(AiAllowanceReader::class)->forWorkspace($workspace, $business);
        $this->assertSame(AiAllowance::UNAVAILABLE, $state->status);
        $this->assertSame('trial_expired', $state->policyKey);
        $this->assertSame(AiRefusalReason::BudgetExhausted, app(AiGateway::class)->complete($this->request($workspace, $business))->refusalReason);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_grace_after_an_unconverted_trial_and_a_locked_account_get_no_ai_but_a_paying_account_in_grace_keeps_its_allowance(): void
    {
        [$ended] = $this->account(WorkspacePlanTier::Growth, now()->subDay());
        $this->assignment($ended, ['grace_started_at' => now()]);
        $this->assertSame(0, $this->policy($ended->fresh())->workspaceCapMicrousd, 'A failed trial is not a paying customer in grace.');

        [$locked] = $this->account(WorkspacePlanTier::Growth);
        $this->assignment($locked, ['locked_at' => now()]);
        $this->assertSame(0, $this->policy($locked->fresh())->workspaceCapMicrousd);

        [$paying] = $this->account(WorkspacePlanTier::Growth);
        $this->assignment($paying, ['grace_started_at' => now()]);
        $this->assertSame(10_000_000, $this->policy($paying->fresh())->workspaceCapMicrousd, 'Grace after a failed renewal keeps full access (Blueprint §27).');

        $this->assignment($paying, ['grace_started_at' => now()->subDays(10)]);
        $this->assertSame(0, $this->policy($paying->fresh())->workspaceCapMicrousd, 'An elapsed grace window is locked.');
    }

    public function test_a_provider_confirmed_conversion_moves_to_the_paid_allowance_and_clears_the_exhausted_state(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(5));
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 3_000_000, 2_400_000);
        $reader = app(AiAllowanceReader::class);
        $this->assertSame(AiAllowance::TRIAL_LIMIT_REACHED, $reader->forWorkspace($workspace, $business)->status);

        // The canonical conversion writer (what a confirmed paid subscription calls): clears the outstanding trial.
        app(EntitlementManager::class)->recoverAccess($workspace, null, 'Provider-confirmed conversion.');

        $policy = $this->policy($workspace->fresh());
        $this->assertSame('growth', $policy->policyKey);
        $this->assertSame(10_000_000, $policy->workspaceCapMicrousd);
        $this->assertSame('2026-09', $policy->periodKey);
        $this->assertSame(AiAllowance::AVAILABLE, $reader->forWorkspace($workspace->fresh(), $business)->status, 'No false exhausted-trial state remains.');
        $this->assertTrue(app(AiGateway::class)->complete($this->request($workspace->fresh(), $business))->ok);
        $this->assertSame(3_000_000, (int) AiUsagePeriod::query()->where('period_key', 'trial')->value('committed_microusd'), 'Trial history is kept, untouched.');
        $this->assertSame(1, AiUsagePeriod::query()->where('period_key', '2026-09')->count());
    }

    public function test_a_plan_upgrade_inside_an_open_month_adopts_the_larger_cap_explicitly_and_a_downgrade_never_shrinks_it(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->openPeriod($workspace, '2026-09', 'core', 5_000_000, 1_000_000);
        $this->assignment($workspace, [
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('id'),
        ]);

        $this->assertTrue(app(AiGateway::class)->complete($this->request($workspace->fresh(), $business))->ok);
        $period = AiUsagePeriod::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(10_000_000, (int) $period->cap_microusd);
        $this->assertSame('growth', $period->policy_key);
        $this->assertGreaterThanOrEqual(1_000_000, (int) $period->committed_microusd, 'Spend is untouched.');

        $this->assignment($workspace, [
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id'),
        ]);
        app(AiGateway::class)->complete($this->request($workspace->fresh(), $business));
        $this->assertSame(10_000_000, (int) AiUsagePeriod::query()->where('workspace_id', $workspace->id)->value('cap_microusd'), 'An open period is never lowered.');
    }

    // ── Retry / idempotency ──────────────────────────────────────────────

    public function test_a_failed_provider_call_releases_its_reservation_and_a_retry_is_charged_once(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $gateway = app(AiGateway::class);
        $this->fake->queueResult(AiCompletionResult::failure());

        $failed = $gateway->complete($this->request($workspace, $business, key: 'k-1'));
        $this->assertFalse($failed->ok);
        $this->assertNull($failed->refusalReason);
        $period = AiUsagePeriod::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(0, (int) $period->reserved_microusd);
        $this->assertSame(0, (int) $period->committed_microusd);

        $ok = $gateway->complete($this->request($workspace, $business, key: 'k-2'));
        $this->assertTrue($ok->ok);
        $this->assertSame(1, AiUsageLedgerEntry::query()->where('status', 'committed')->count());
    }

    // ── What the customer is told ────────────────────────────────────────

    public function test_billing_and_the_allowance_reader_agree_on_the_trial_limit(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth, now()->addDays(10));
        $this->openPeriod($workspace, 'trial', 'trial', 3_000_000, 3_000_000);

        $summary = app(AiUsagePresenter::class)->forBillingPage($business, true, false);

        $this->assertSame(AiUsageState::LimitReached, $summary->state);
        $this->assertSame("Your trial's included AI is used up. Everything else keeps working.", $summary->sentence);
        $this->assertSame(AiAllowance::TRIAL_LIMIT_REACHED, app(AiAllowanceReader::class)->forWorkspace($workspace, $business)->status);
    }

    public function test_the_trial_limit_copy_has_the_required_meaning_and_reveals_no_internal_cost(): void
    {
        $copy = __('ask_ai.trial_limit.title') . ' ' . __('ask_ai.trial_limit.body') . ' ' . __('ask_ai.trial_limit.cta');

        $this->assertStringContainsString("You've used your trial's included AI", $copy);
        $this->assertStringContainsString('Upgrade to a paid MotionGrove plan', $copy);
        $this->assertStringContainsString('Your other MotionGrove features will keep working.', $copy);
        $this->assertStringContainsString('Upgrade plan', $copy);
        $this->assertDoesNotMatchRegularExpression('/\$|token|gpt|openai|model|microusd|unlimited/i', $copy);
    }

    public function test_no_amount_or_model_literal_was_added_outside_config_and_the_resolver(): void
    {
        foreach (['Assistant', 'Ai'] as $dir) {
            foreach (glob(base_path("app/Library/{$dir}/*.php")) as $file) {
                if (str_ends_with($file, 'AiBudgetPolicyResolver.php') || str_ends_with($file, 'AiModelRouter.php')) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/3_000_000|3000000|1_500_000|gpt-4o/', (string) file_get_contents($file), basename($file));
            }
        }
    }
}
