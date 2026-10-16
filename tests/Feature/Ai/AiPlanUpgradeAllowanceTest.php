<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiAllowance;
use App\Library\Ai\AiAllowanceReader;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Providers\FakeAiCompletionClient;
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
 * PR #460 review, P1: a paid Workspace that exhausted its Core allowance and then upgraded to Growth in the SAME calendar
 * month must see the larger allowance immediately. The period keeps the cap it snapshotted until the gateway's reserve() adopts
 * the upgraded one, and a refusal recorded under the old cap used to keep the allowance preflight saying "limit reached" — so
 * the gateway (the only place the cap is adopted) was never reached.
 */
class AiPlanUpgradeAllowanceTest extends TestCase
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
        $this->fake->setDefaultResult(AiCompletionResult::success('ok', 'gpt-4o-mini', 400, 40));
        $this->app->instance(AiCompletionClient::class, $this->fake);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: Business} */
    private function account(WorkspacePlanTier $tier): array
    {
        [, $business, $workspace] = $this->tenant($tier, 'Upgrade Venue ' . Str::random(4), 'Upgrade Account ' . Str::random(4));

        return [$workspace->fresh(), $business->fresh()];
    }

    private function changeTier(Workspace $workspace, string $tier): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update([
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id'),
        ]);
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
    }

    private function period(Workspace $workspace, string $key, string $policy, int $cap, int $committed, string $scope = 'workspace', ?int $scopeId = null): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => $scope, 'scope_id' => $scopeId ?? $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => $key,
            'policy_key' => $policy, 'policy_version' => 1, 'cap_microusd' => $cap, 'reserved_microusd' => 0,
            'committed_microusd' => $committed, 'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ask(Workspace $workspace, ?Business $business, string $content = 'hello'): \App\Library\Ai\AiResult
    {
        return app(AiGateway::class)->complete(AiRequest::forWorkspace(
            $workspace, $business, AiUsageCategory::AskAssistant, AiLane::Interactive, AiModelRoute::Assistant,
            [['role' => 'user', 'content' => $content]], 300, (string) Str::uuid(), null,
        ));
    }

    private function reader(Workspace $workspace, Business $business): AiAllowance
    {
        return app(AiAllowanceReader::class)->forWorkspace($workspace->fresh(), $business);
    }

    private function workspacePeriod(Workspace $workspace): AiUsagePeriod
    {
        return AiUsagePeriod::query()->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->firstOrFail();
    }

    public function test_core_exhausted_then_upgraded_to_growth_in_the_same_month_has_the_larger_allowance_immediately(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 5_000_000);

        // Under Core the allowance is used up: the gateway refuses (writing the refusal the preflight keys on).
        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->ask($workspace, $business)->refusalReason);
        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertSame(0, $this->fake->callCount());

        // Provider-confirmed upgrade to Growth, same billing period.
        $this->changeTier($workspace, 'growth');

        $allowance = $this->reader($workspace, $business);
        $this->assertTrue($allowance->usable(), 'The larger allowance is available at once; no other AI feature had to run first. Got: ' . $allowance->status);
        $this->assertSame(10_000_000, (int) $this->workspacePeriod($workspace)->cap_microusd, 'The open period adopted the Growth cap, upward only.');
        $this->assertSame('growth', $this->workspacePeriod($workspace)->policy_key);
        $this->assertSame(5_000_000, (int) $this->workspacePeriod($workspace)->committed_microusd, 'Spend already committed is untouched.');

        $this->assertTrue($this->ask($workspace, $business)->ok, 'And a call really goes through the gateway.');
        $this->assertSame(1, $this->fake->callCount());
    }

    public function test_a_core_workspace_refused_with_a_little_room_left_is_unblocked_by_the_upgrade_too(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 4_999_990); // not at the cap — but less than any call needs
        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->ask($workspace, $business)->refusalReason);
        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);

        $this->changeTier($workspace, 'growth');

        $this->assertTrue($this->reader($workspace, $business)->usable());
        $this->assertTrue($this->ask($workspace, $business)->ok);
    }

    public function test_an_exhausted_allowance_without_an_upgrade_stays_exhausted_and_is_not_bypassed(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 5_000_000);
        $this->ask($workspace, $business);

        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->ask($workspace, $business)->refusalReason);
        $this->assertSame(5_000_000, (int) $this->workspacePeriod($workspace)->cap_microusd, 'Nothing was raised.');
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_a_refusal_recorded_after_the_upgrade_still_counts(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 5_000_000);
        $this->ask($workspace, $business);
        $this->changeTier($workspace, 'growth');
        $this->assertTrue($this->reader($workspace, $business)->usable());

        // Growth's own allowance is then used up: that refusal is binding.
        DB::table('ai_usage_periods')->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->update(['committed_microusd' => 10_000_000]);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->ask($workspace, $business)->refusalReason);
        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertLessThanOrEqual(10_000_000, (int) $this->workspacePeriod($workspace)->committed_microusd + (int) $this->workspacePeriod($workspace)->reserved_microusd);
    }

    public function test_a_downgrade_never_shrinks_an_open_period_and_the_reader_does_not_raise_it_either(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth);
        $this->period($workspace, '2026-09', 'growth', 10_000_000, 1_000_000);
        $this->changeTier($workspace, 'core');

        $this->assertTrue($this->reader($workspace, $business)->usable());
        $this->assertSame(10_000_000, (int) $this->workspacePeriod($workspace)->cap_microusd);
        $this->assertSame('growth', $this->workspacePeriod($workspace)->policy_key);
    }

    public function test_a_trial_allowance_is_one_non_resetting_three_dollars_and_is_never_raised_by_the_reader(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Growth);
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['trial_ends_at' => now()->addDays(5), 'is_complimentary' => false]);
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        $this->period($workspace, 'trial', 'trial', 3_000_000, 3_000_000);
        $this->ask($workspace, $business);

        $this->assertSame(AiAllowance::TRIAL_LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertSame(AiAllowance::TRIAL_LIMIT_REACHED, $this->reader($workspace, $business)->status, 'Reading twice changes nothing.');
        $this->assertSame(3_000_000, (int) $this->workspacePeriod($workspace)->cap_microusd);
        $this->assertSame('trial', $this->workspacePeriod($workspace)->policy_key);
    }

    public function test_an_agency_business_that_spent_its_own_allowance_stays_limited_after_the_workspace_upgrade(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Agency);
        $this->period($workspace, '2026-09', 'agency', 25_000_000, 6_000_000);
        $this->period($workspace, '2026-09', 'agency', 6_000_000, 6_000_000, 'business', (int) $business->id);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->ask($workspace, $business)->refusalReason);

        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertSame(0, $this->fake->callCount());
        $this->assertSame(6_000_000, (int) AiUsagePeriod::query()->where('scope_type', 'business')->where('scope_id', $business->id)->value('cap_microusd'), 'The per-Business cap is untouched.');
    }

    public function test_reconciling_twice_and_a_concurrent_reservation_leave_the_counters_consistent(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 5_000_000);
        $this->ask($workspace, $business);
        $this->changeTier($workspace, 'growth');

        $this->reader($workspace, $business);
        $this->reader($workspace, $business);
        $this->assertTrue($this->ask($workspace, $business)->ok);

        $period = $this->workspacePeriod($workspace);
        $this->assertSame(10_000_000, (int) $period->cap_microusd);
        $this->assertSame(0, (int) $period->reserved_microusd);
        $this->assertSame(1, AiUsagePeriod::query()->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->count());
        $this->assertSame((int) $period->committed_microusd - 5_000_000, (int) AiUsageLedgerEntry::query()->where('workspace_id', $workspace->id)->where('status', 'committed')->sum('actual_cost_microusd'));
    }

    private function askAs(Workspace $workspace, ?Business $business, AiUsageCategory $category, int $maxOutputTokens): \App\Library\Ai\AiResult
    {
        return app(AiGateway::class)->complete(AiRequest::forWorkspace(
            $workspace, $business, $category, AiLane::Interactive, AiModelRoute::Assistant,
            [['role' => 'user', 'content' => 'hello']], $maxOutputTokens, (string) Str::uuid(), null,
        ));
    }

    // PR #460 review: a refusal of an UNRELATED, bigger request must not block a cheaper Ask MotionGrove turn.
    public function test_a_refused_website_generation_does_not_block_an_affordable_assistant_turn(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 4_940_000); // a little room left, not none

        // A website generation estimated at 67,000 µUSD was refused earlier this period (room is only 60,000).
        DB::table('ai_usage_ledger')->insert([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'category' => 'website_generation',
            'lane' => 'interactive', 'model_route' => 'reasoning', 'provider' => 'openai', 'price_version' => 1, 'status' => 'refused',
            'refusal_reason' => 'budget_exhausted', 'refusal_scope' => 'workspace', 'estimated_cost_microusd' => 67_000, 'period_key' => '2026-09',
            'idempotency_key' => 'website-' . Str::uuid(), 'created_at' => now(),
        ]);

        $this->assertTrue($this->reader($workspace, $business)->usable(), 'The assistant preflight is not blocked by that unrelated refusal.');
        $this->assertTrue($this->askAs($workspace, $business, AiUsageCategory::AskAssistant, 300)->ok, 'And the gateway (the authority) accepts the cheaper assistant call.');
    }

    public function test_a_refused_assistant_request_that_still_would_not_fit_still_blocks_the_preflight(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Core);
        $this->period($workspace, '2026-09', 'core', 5_000_000, 4_999_990);

        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->askAs($workspace, $business, AiUsageCategory::AskAssistant, 300)->refusalReason);
        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status, 'Hard cap behaviour for assistant requests is unchanged.');
    }

    // PR #460 review: the per-Business (Agency) preflight is request-aware too.
    public function test_a_refused_big_agency_business_operation_does_not_block_an_affordable_assistant_turn(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Agency);
        $this->period($workspace, '2026-09', 'agency', 25_000_000, 1_000_000);
        $this->period($workspace, '2026-09', 'agency', 6_000_000, 5_940_000, 'business', (int) $business->id); // 60,000 left under the Business sub-cap
        DB::table('ai_usage_ledger')->insert([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'category' => 'website_generation',
            'lane' => 'interactive', 'model_route' => 'reasoning', 'provider' => 'openai', 'price_version' => 1, 'status' => 'refused',
            'refusal_reason' => 'budget_exhausted', 'refusal_scope' => 'business', 'estimated_cost_microusd' => 67_000, 'period_key' => '2026-09',
            'idempotency_key' => 'website-' . Str::uuid(), 'created_at' => now(),
        ]);

        $this->assertTrue($this->reader($workspace, $business)->usable(), 'An unrelated Business-scoped refusal does not block the assistant preflight.');
        $this->assertTrue($this->askAs($workspace, $business, AiUsageCategory::AskAssistant, 300)->ok, 'The gateway accepts the cheaper assistant call.');
        $this->assertSame(6_000_000, (int) AiUsagePeriod::query()->where('scope_type', 'business')->where('scope_id', $business->id)->value('cap_microusd'), 'The per-Business cap is untouched.');
    }

    public function test_an_agency_business_with_no_room_for_an_assistant_call_is_still_limited_and_the_gateway_still_refuses(): void
    {
        [$workspace, $business] = $this->account(WorkspacePlanTier::Agency);
        $this->period($workspace, '2026-09', 'agency', 25_000_000, 1_000_000);
        $this->period($workspace, '2026-09', 'agency', 6_000_000, 5_999_990, 'business', (int) $business->id);

        $this->assertSame(AiRefusalReason::BudgetExhausted, $this->askAs($workspace, $business, AiUsageCategory::AskAssistant, 300)->refusalReason);
        $this->assertSame(AiAllowance::LIMIT_REACHED, $this->reader($workspace, $business)->status);
        $this->assertSame(0, $this->fake->callCount());
    }
}
