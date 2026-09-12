<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine Contract §10, §11, §13
 * (slice AI-1) — the ledger, budget and routing test contract:
 * T-BUD-1 through T-BUD-9, T-AI-GATE-2, T-ROUTE-1, T-ROUTE-2.
 */
class AiGatewayTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => false]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);
    }

    private function gateway(): AiGateway
    {
        return app(AiGateway::class);
    }

    private function router(): AiModelRouter
    {
        return app(AiModelRouter::class);
    }

    private function buildRequest(
        Workspace $workspace,
        ?Business $business = null,
        AiUsageCategory $category = AiUsageCategory::WebsiteGeneration,
        AiLane $lane = AiLane::Product,
        ?AiModelRoute $route = null,
        int $maxOutputTokens = 100,
        ?string $idempotencyKey = null,
    ): AiRequest {
        $route ??= $this->router()->defaultRouteFor($category);

        return new AiRequest(
            workspace: $workspace,
            business: $business,
            category: $category,
            lane: $lane,
            route: $route,
            messages: [['role' => 'user', 'content' => 'hello world']],
            maxOutputTokens: $maxOutputTokens,
            idempotencyKey: $idempotencyKey ?? (string) Str::uuid(),
            actorUserId: null,
        );
    }

    // =================================================================
    // T-BUD-1 — reservation at the exact cap boundary
    // =================================================================

    public function test_reservation_at_exact_cap_boundary_passes_one_micro_usd_over_refuses(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        // Core cap is 5_000_000. Force enforcement on so an over-cap
        // request is genuinely refused (not merely observed).
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $capMicrousd = config('ai.budgets.core.workspace_cap_microusd');
        $request = $this->buildRequest($workspace, maxOutputTokens: 1);
        $estimate = $this->router()->estimateCostMicrousd($request->route, $request->messages, $request->maxOutputTokens);

        // Pre-seed the period so exactly $estimate of headroom remains —
        // a request landing exactly at the cap.
        $periodKey = Carbon::now('UTC')->format('Y-m');
        $period = AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $capMicrousd,
            'committed_microusd' => $capMicrousd - $estimate,
        ]);

        $this->fakeClient->setDefaultResult(AiCompletionResult::success('ok', 'gpt-4o-mini', 1, 1));

        $result = $this->gateway()->complete($request);

        $this->assertTrue($result->ok, 'A request landing exactly at the remaining cap must succeed.');

        // One microUSD over: pre-seed so only ($estimate - 1) remains.
        $period->update(['committed_microusd' => $capMicrousd - $estimate + 1, 'reserved_microusd' => 0]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);

        $secondRequest = $this->buildRequest($workspace, maxOutputTokens: 1);
        $secondResult = $this->gateway()->complete($secondRequest);

        $this->assertFalse($secondResult->ok, 'A request landing one microUSD over the cap must be refused.');
        $this->assertSame(AiRefusalReason::BudgetExhausted, $secondResult->refusalReason);
        $this->assertSame(0, $this->fakeClient->callCount(), 'A refused request must never reach the provider.');
    }

    // =================================================================
    // T-BUD-2 — Agency "whichever first"
    // =================================================================

    public function test_agency_business_cap_is_hit_before_the_workspace_cap(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $businessCap = config('ai.budgets.agency.business_cap_microusd');
        $periodKey = Carbon::now('UTC')->format('Y-m');

        // Pre-fill the Business's own sub-cap to its limit, while the
        // Workspace cap ($25) has plenty of room left.
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_BUSINESS,
            'scope_id' => $business->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'agency',
            'policy_version' => 1,
            'cap_microusd' => $businessCap,
            'committed_microusd' => $businessCap,
        ]);

        $request = $this->buildRequest($workspace, $business, AiUsageCategory::WebsiteGeneration);
        $result = $this->gateway()->complete($request);

        $this->assertFalse($result->ok, 'The per-Business cap must refuse even though the Workspace cap has room.');
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
    }

    public function test_agency_workspace_cap_is_hit_before_any_one_businesss_cap(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $workspaceCap = config('ai.budgets.agency.workspace_cap_microusd');
        $periodKey = Carbon::now('UTC')->format('Y-m');

        // The Workspace cap is exhausted, but this one Business has never
        // spent anything.
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'agency',
            'policy_version' => 1,
            'cap_microusd' => $workspaceCap,
            'committed_microusd' => $workspaceCap,
        ]);

        $request = $this->buildRequest($workspace, $business, AiUsageCategory::WebsiteGeneration);
        $result = $this->gateway()->complete($request);

        $this->assertFalse($result->ok, 'The Workspace cap must refuse even though this Business has its own room.');
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
    }

    // =================================================================
    // T-BUD-3 — interactive lane at 30%
    // =================================================================

    public function test_interactive_lane_is_refused_at_its_share_while_product_still_succeeds(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $cap = config('ai.budgets.core.workspace_cap_microusd');
        $shareBps = config('ai.budgets.core.interactive_share_bps');
        $interactiveCap = intdiv($cap * $shareBps, 10_000);
        $periodKey = Carbon::now('UTC')->format('Y-m');

        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $cap,
            // Interactive lane already at its own cap, but the OVERALL
            // Workspace committed total is far below the full $5 cap.
            'committed_microusd' => $interactiveCap,
            'interactive_committed_microusd' => $interactiveCap,
        ]);

        $interactiveRequest = $this->buildRequest($workspace, category: AiUsageCategory::CooInteractive, lane: AiLane::Interactive);
        $interactiveResult = $this->gateway()->complete($interactiveRequest);

        $this->assertFalse($interactiveResult->ok, 'Interactive lane must refuse once its own share is spent.');
        $this->assertSame(AiRefusalReason::InteractiveShareExhausted, $interactiveResult->refusalReason);

        // Product lane is NOT capped at the interactive share — it may
        // use everything the interactive lane has not committed/reserved.
        $productRequest = $this->buildRequest($workspace, category: AiUsageCategory::WebsiteGeneration, lane: AiLane::Product);
        $productResult = $this->gateway()->complete($productRequest);

        $this->assertTrue($productResult->ok, 'Product lane must still succeed up to the remainder of the Workspace cap.');
    }

    // =================================================================
    // T-BUD-4 — concurrent reservations never overshoot. See
    // AiGatewayConcurrencyTest — real cross-process concurrency needs
    // committed rows, so that file deliberately does not use
    // RefreshDatabase, mirroring ConcurrentTopUpConcurrencyTest's own
    // precedent exactly.
    // =================================================================

    // =================================================================
    // T-BUD-5 — commit uses actual usage x price version; release;
    // stale reservations expire
    // =================================================================

    public function test_commit_uses_actual_usage_times_the_reserved_price_version_and_releases_the_remainder(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->fakeClient->setDefaultResult(AiCompletionResult::success(
            content: 'the generated content',
            providerModel: 'gpt-4o-mini',
            inputTokens: 500,
            outputTokens: 100,
            cachedInputTokens: 0,
        ));

        $request = $this->buildRequest($workspace, maxOutputTokens: 800);
        $result = $this->gateway()->complete($request);

        $this->assertTrue($result->ok);
        $entry = $result->ledgerEntry->fresh();

        $this->assertSame(AiUsageEntryStatus::Committed, $entry->status);
        $this->assertNotNull($entry->actual_cost_microusd);
        $this->assertLessThan($entry->estimated_cost_microusd, $entry->actual_cost_microusd, 'The actual cost of a short reply must be less than the worst-case estimate reserved for it.');

        $routeConfig = config('ai.routes.routine');
        $expectedActual = (int) ceil(500 * $routeConfig['input_price_microusd_per_mtok'] / 1_000_000)
            + (int) ceil(100 * $routeConfig['output_price_microusd_per_mtok'] / 1_000_000);
        $this->assertSame($expectedActual, $entry->actual_cost_microusd);
        $this->assertSame($routeConfig['price_version'], $entry->price_version);

        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $workspace->id)->first();
        $this->assertSame($expectedActual, $period->committed_microusd);
        $this->assertSame(0, $period->reserved_microusd, 'The unused portion of the reservation must be released.');
    }

    public function test_a_stale_reservation_expires_and_is_released_never_auto_committed(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $periodKey = Carbon::now('UTC')->format('Y-m');

        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => config('ai.budgets.core.workspace_cap_microusd'),
            'reserved_microusd' => 1000,
        ]);

        $entry = AiUsageLedgerEntry::create([
            'workspace_id' => $workspace->id,
            'business_id' => null,
            'category' => AiUsageCategory::WebsiteGeneration,
            'lane' => AiLane::Product,
            'model_route' => AiModelRoute::Routine,
            'provider' => 'openai',
            'provider_model' => null,
            'price_version' => 1,
            'status' => AiUsageEntryStatus::Reserved,
            'estimated_cost_microusd' => 1000,
            'period_key' => $periodKey,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => Carbon::now()->subMinutes((int) config('ai.reservation_expiry_minutes') + 1),
        ]);

        app(\App\Library\Ai\AiUsageLedgerManager::class)->expireStaleReservations();

        $entry->refresh();
        $this->assertSame(AiUsageEntryStatus::Released, $entry->status);
        $this->assertSame(0, $entry->actual_cost_microusd);

        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $workspace->id)->first();
        $this->assertSame(0, $period->reserved_microusd);
        $this->assertSame(0, $period->committed_microusd, 'A stale reservation must be released, never committed.');
    }

    public function test_a_fresh_reservation_is_not_touched_by_expiry(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $periodKey = Carbon::now('UTC')->format('Y-m');

        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => config('ai.budgets.core.workspace_cap_microusd'),
            'reserved_microusd' => 1000,
        ]);

        $entry = AiUsageLedgerEntry::create([
            'workspace_id' => $workspace->id,
            'category' => AiUsageCategory::WebsiteGeneration,
            'lane' => AiLane::Product,
            'model_route' => AiModelRoute::Routine,
            'provider' => 'openai',
            'price_version' => 1,
            'status' => AiUsageEntryStatus::Reserved,
            'estimated_cost_microusd' => 1000,
            'period_key' => $periodKey,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);

        app(\App\Library\Ai\AiUsageLedgerManager::class)->expireStaleReservations();

        $this->assertSame(AiUsageEntryStatus::Reserved, $entry->fresh()->status);
    }

    // =================================================================
    // T-BUD-6 — unassigned/inactive/suspended refuses everything
    // =================================================================

    public function test_unassigned_workspace_refuses_every_call(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $result = $this->gateway()->complete($this->buildRequest($workspace));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    public function test_inactive_plan_refuses_every_call(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Fixture deactivation.');

        $result = $this->gateway()->complete($this->buildRequest($workspace->fresh()));

        $this->assertFalse($result->ok);
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    public function test_suspended_plan_refuses_every_call(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture suspension.');

        $result = $this->gateway()->complete($this->buildRequest($workspace->fresh()));

        $this->assertFalse($result->ok);
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    public function test_ai_disabled_globally_refuses_before_any_policy_or_ledger_work(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        config(['services.openai.active' => false]);

        $result = $this->gateway()->complete($this->buildRequest($workspace));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::AiDisabled, $result->refusalReason);
        $this->assertSame(0, $this->fakeClient->callCount());
        $this->assertSame(0, AiUsageLedgerEntry::count(), 'The global kill switch must record nothing at all.');
    }

    // =================================================================
    // T-BUD-8 — observation mode vs. hard enforcement
    // =================================================================

    public function test_existing_category_over_cap_is_recorded_and_counted_but_never_refused_while_observation_mode_is_on(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        config(['ai.enforce_budgets_for_existing_categories' => false]);

        $cap = config('ai.budgets.core.workspace_cap_microusd');
        $periodKey = Carbon::now('UTC')->format('Y-m');
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $cap,
            'committed_microusd' => $cap, // already fully spent
        ]);

        $request = $this->buildRequest($workspace, category: AiUsageCategory::WebsiteGeneration);
        $result = $this->gateway()->complete($request);

        $this->assertTrue($result->ok, 'An existing category must not be refused solely for exceeding its budget during observation mode.');
        $this->assertSame(1, $this->fakeClient->callCount(), 'The call must still reach the provider.');
        $this->assertSame(AiUsageEntryStatus::Committed, $result->ledgerEntry->fresh()->status, 'The call must still be recorded in the ledger.');

        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $workspace->id)->first();
        $this->assertGreaterThan($cap, $period->committed_microusd, 'Usage must still be counted toward the Workspace total even past the nominal cap.');
    }

    public function test_hard_enforced_category_refuses_at_cap_regardless_of_the_observation_flag(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        config(['ai.enforce_budgets_for_existing_categories' => false]);

        $cap = config('ai.budgets.core.workspace_cap_microusd');
        $periodKey = Carbon::now('UTC')->format('Y-m');
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $cap,
            'committed_microusd' => $cap,
        ]);

        foreach ([AiUsageCategory::CooDiagnosis, AiUsageCategory::CooInteractive, AiUsageCategory::ConversationCompaction] as $category) {
            $this->fakeClient = new FakeAiCompletionClient();
            $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);

            $lane = $category === AiUsageCategory::CooInteractive ? AiLane::Interactive : AiLane::Product;
            $request = $this->buildRequest($workspace, category: $category, lane: $lane);
            $result = $this->gateway()->complete($request);

            $this->assertFalse($result->ok, "{$category->value} must be refused at cap even while observation mode is on.");
            $this->assertSame(0, $this->fakeClient->callCount(), "{$category->value} must never reach the provider once refused.");
        }
    }

    // =================================================================
    // T-AI-GATE-2 — shared pool: one category's spend reduces another's
    // room, no private pool
    // =================================================================

    public function test_spending_by_one_category_reduces_availability_to_another_category(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $cap = 500;
        $periodKey = Carbon::now('UTC')->format('Y-m');
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $cap,
        ]);

        config(['ai.routes.routine.max_request_cost_microusd' => 10_000_000]);
        config(['ai.routes.routine.output_price_microusd_per_mtok' => 3_000_000]);

        // Category A (website_generation) spends almost the entire
        // shared pool.
        $this->fakeClient->setDefaultResult(AiCompletionResult::success('a', 'gpt-4o-mini', 1, 150));
        $firstRequest = $this->buildRequest($workspace, $business, AiUsageCategory::WebsiteGeneration, maxOutputTokens: 150);
        $firstResult = $this->gateway()->complete($firstRequest);
        $this->assertTrue($firstResult->ok);

        $periodAfterFirst = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $workspace->id)->first();
        $remaining = $cap - $periodAfterFirst->committed_microusd;
        $this->assertLessThan($cap, $remaining, 'Category A must have actually spent from the shared pool.');

        // Category B (campaign_message_draft) — a DIFFERENT category,
        // requesting more than what A left behind — must be refused,
        // proving there is no private per-category pool.
        $secondRequest = $this->buildRequest($workspace, category: AiUsageCategory::CampaignMessageDraft, maxOutputTokens: 100_000);
        $secondResult = $this->gateway()->complete($secondRequest);

        $this->assertFalse($secondResult->ok, "A different category must be constrained by category A's spending — there is no private pool.");
    }

    // =================================================================
    // T-ROUTE-1 — category -> route mapping, downgrade on insufficient
    // headroom
    // =================================================================

    public function test_each_ai1_category_maps_to_its_configured_route(): void
    {
        $router = $this->router();

        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::CooDiagnosis));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::CooInteractive));
        $this->assertSame(AiModelRoute::Compaction, $router->defaultRouteFor(AiUsageCategory::ConversationCompaction));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::WebsiteGeneration));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::CampaignMessageDraft));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::AgencyProspectReply));
    }

    public function test_reasoning_downgrades_to_routine_when_headroom_is_insufficient(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $reasoningConfig = config('ai.routes.reasoning');
        $requiredHeadroom = $reasoningConfig['max_request_cost_microusd'] * $reasoningConfig['min_headroom_multiple'];

        // The policy's Workspace cap is fixed by config('ai.budgets.core.*')
        // regardless of what a period row's own cap_microusd says — the
        // headroom check compares against the POLICY cap minus already
        // committed/reserved, so the period row must be pre-committed
        // down to just under the required headroom, not given a smaller
        // cap of its own.
        $coreCap = config('ai.budgets.core.workspace_cap_microusd');
        $periodKey = Carbon::now('UTC')->format('Y-m');
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $coreCap,
            'committed_microusd' => $coreCap - $requiredHeadroom + 1,
        ]);

        $request = $this->buildRequest($workspace, category: AiUsageCategory::CooDiagnosis, route: AiModelRoute::Reasoning, maxOutputTokens: 10);
        $result = $this->gateway()->complete($request);

        $this->assertTrue($result->ok);
        $this->assertSame(AiModelRoute::Routine, $result->ledgerEntry->fresh()->model_route, 'Insufficient headroom must downgrade reasoning to routine.');
    }

    public function test_reasoning_is_used_when_headroom_is_sufficient(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);

        $request = $this->buildRequest($workspace, category: AiUsageCategory::CooDiagnosis, route: AiModelRoute::Reasoning, maxOutputTokens: 10);
        $result = $this->gateway()->complete($request);

        $this->assertTrue($result->ok);
        $this->assertSame(AiModelRoute::Reasoning, $result->ledgerEntry->fresh()->model_route, 'A Workspace with ample headroom must keep the requested reasoning route.');
    }

    public function test_a_request_exceeding_its_routes_max_cost_is_refused_as_too_expensive(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        config(['ai.routes.routine.max_request_cost_microusd' => 1]);

        $request = $this->buildRequest($workspace, maxOutputTokens: 500);
        $result = $this->gateway()->complete($request);

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::RequestTooExpensive, $result->refusalReason);
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    // =================================================================
    // T-BUD-7 — the policy comes only from config/ai.php; no amount
    // literal anywhere else
    // =================================================================

    public function test_no_budget_amount_literal_exists_outside_config_and_its_resolver(): void
    {
        // Scoped to the AI budget system's OWN code (app/Library/Ai/**
        // plus the three existing call sites), not the whole application
        // — other subsystems (RFC-005 usage/billing, for one) legitimately
        // use these same round numbers for their own, unrelated amounts,
        // and a literal there says nothing about whether the AI budget
        // system duplicates its own numbers.
        $amounts = ['1_500_000', '5_000_000', '10_000_000', '25_000_000', '6_000_000'];
        $scannedPaths = array_merge(
            iterator_to_array((new \Symfony\Component\Finder\Finder())->files()->in(app_path('Library/Ai'))->name('*.php')),
            [
                new \SplFileInfo(app_path('Library/AgencyProspecting/OpenAiAgencyProspectingClient.php')),
                new \SplFileInfo(app_path('Library/Website/WebsiteAiGenerationClient.php')),
                new \SplFileInfo(app_path('Http/Controllers/Customer/CampaignController.php')),
            ],
        );

        $offenders = [];

        foreach ($scannedPaths as $file) {
            if (str_ends_with($file->getRealPath(), 'AiBudgetPolicyResolver.php')) {
                continue; // only reads config, never hardcodes — verified by its own source review
            }

            $contents = file_get_contents($file->getRealPath());

            foreach ($amounts as $amount) {
                if (str_contains($contents, $amount)) {
                    $offenders[] = basename($file->getRealPath()) . " ({$amount})";
                }
            }
        }

        $this->assertSame([], $offenders, 'A budget amount literal was found outside config/ai.php: ' . implode(', ', $offenders));
    }
}
