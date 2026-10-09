<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiBusinessCategoryCeiling;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 1 — the AI budget seam: the `content_autopilot` category, the `content_writer` route, and
 * the per-Business hard ceiling AiGateway enforces for that category (a safety ceiling, not a target).
 */
class AiBusinessCategoryCeilingTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true, 'ai.enforce_budgets_for_existing_categories' => false]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fake);
    }

    private function request(Workspace $workspace, Business $business, AiUsageCategory $category = AiUsageCategory::ContentAutopilot, ?AiModelRoute $route = null): AiRequest
    {
        return new AiRequest(
            workspace: $workspace,
            business: $business,
            category: $category,
            lane: AiLane::Product,
            route: $route ?? app(AiModelRouter::class)->defaultRouteFor($category),
            messages: [['role' => 'user', 'content' => 'write something short']],
            maxOutputTokens: 100,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
        );
    }

    private function ceiling(): AiBusinessCategoryCeiling
    {
        return app(AiBusinessCategoryCeiling::class);
    }

    private function period(): string
    {
        return now('UTC')->format('Y-m');
    }

    private function ledgerRow(Workspace $workspace, Business $business, array $overrides): AiUsageLedgerEntry
    {
        return AiUsageLedgerEntry::query()->create(array_merge([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'business_id' => $business->id,
            'category' => 'content_autopilot',
            'lane' => 'product',
            'model_route' => 'routine',
            'provider' => 'openai',
            'price_version' => 1,
            'status' => 'committed',
            'period_key' => $this->period(),
            'idempotency_key' => (string) Str::uuid(),
            'estimated_cost_microusd' => 0,
            'actual_cost_microusd' => 0,
        ], $overrides));
    }

    public function test_the_category_and_routes_are_wired_and_the_default_is_the_cheap_route(): void
    {
        $router = app(AiModelRouter::class);

        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::ContentAutopilot));
        $this->assertTrue(AiUsageCategory::ContentAutopilot->isAlwaysHardEnforced());
        $this->assertFalse(AiUsageCategory::ContentAutopilot->requiresCooEntitlement());
        $this->assertFalse(AiUsageCategory::ContentAutopilot->isDormancyGated(), 'Autopilot works while the owner is away');

        $writer = $router->config(AiModelRoute::ContentWriter);
        $routine = $router->config(AiModelRoute::Routine);
        $this->assertGreaterThan($routine['output_price_microusd_per_mtok'], $writer['output_price_microusd_per_mtok'], 'the writer is the stronger route');
        $this->assertGreaterThan($routine['max_output_tokens'], $writer['max_output_tokens']);
        $this->assertNotSame(AiModelRoute::Reasoning, $router->resolveAffordableRoute(AiModelRoute::ContentWriter, 0), 'no downgrade path targets the writer');
        $this->assertSame(AiModelRoute::ContentWriter, $router->resolveAffordableRoute(AiModelRoute::ContentWriter, 0));
    }

    public function test_the_ceiling_is_policy_not_a_target_and_has_no_owner_setter(): void
    {
        $this->assertSame(500_000, $this->ceiling()->targetMicrousd(AiUsageCategory::ContentAutopilot), '$0.50 normal target');
        $this->assertSame(1_000_000, $this->ceiling()->hardCeilingMicrousd(AiUsageCategory::ContentAutopilot), '$1.00 hard ceiling');
        $this->assertNull($this->ceiling()->hardCeilingMicrousd(AiUsageCategory::WebsiteGeneration), 'other categories have no per-Business ceiling');
    }

    public function test_spend_counts_committed_billed_failures_and_live_reservations_only(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Studio', 'Other');

        $this->ledgerRow($workspace, $business, ['status' => 'committed', 'actual_cost_microusd' => 300, 'estimated_cost_microusd' => 900]);
        $this->ledgerRow($workspace, $business, ['status' => 'failed', 'actual_cost_microusd' => 50, 'estimated_cost_microusd' => 900]);
        $this->ledgerRow($workspace, $business, ['status' => 'reserved', 'actual_cost_microusd' => null, 'estimated_cost_microusd' => 100]);
        $this->ledgerRow($workspace, $business, ['status' => 'released', 'actual_cost_microusd' => null, 'estimated_cost_microusd' => 999]);
        $this->ledgerRow($workspace, $business, ['status' => 'refused', 'actual_cost_microusd' => null, 'estimated_cost_microusd' => 999]);
        $this->ledgerRow($workspace, $business, ['status' => 'committed', 'actual_cost_microusd' => 777, 'period_key' => '1999-01']);
        $this->ledgerRow($workspace, $business, ['status' => 'committed', 'actual_cost_microusd' => 555, 'category' => 'website_generation']);
        $this->ledgerRow($otherWorkspace, $otherBusiness, ['status' => 'committed', 'actual_cost_microusd' => 444]);

        $this->assertSame(450, $this->ceiling()->spentMicrousd($business, AiUsageCategory::ContentAutopilot, $this->period()));
        $this->assertSame(444, $this->ceiling()->spentMicrousd($otherBusiness, AiUsageCategory::ContentAutopilot, $this->period()));
        $this->assertSame(1_000_000 - 450, $this->ceiling()->remainingMicrousd($business, AiUsageCategory::ContentAutopilot, $this->period()));
    }

    public function test_a_call_under_the_ceiling_succeeds_and_is_recorded_under_the_category_and_route(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $result = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::ContentAutopilot, AiModelRoute::ContentWriter));

        $this->assertTrue($result->ok);
        $row = AiUsageLedgerEntry::query()->where('business_id', $business->id)->first();
        $this->assertSame(AiUsageCategory::ContentAutopilot, $row->category);
        $this->assertSame(AiModelRoute::ContentWriter, $row->model_route);
        $this->assertGreaterThan(0, $this->ceiling()->spentMicrousd($business, AiUsageCategory::ContentAutopilot, $this->period()));
    }

    public function test_the_hard_ceiling_refuses_before_any_reservation_or_provider_call(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 0]);

        $result = app(AiGateway::class)->complete($this->request($workspace, $business));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::CategoryCeilingReached, $result->refusalReason);
        $this->assertSame(0, $this->fake->callCount(), 'no provider call');
        $this->assertSame(0, AiUsageLedgerEntry::query()->count(), 'nothing reserved');
    }

    public function test_the_ceiling_stops_the_next_call_once_the_period_spend_reaches_it(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $first = app(AiGateway::class)->complete($this->request($workspace, $business));
        $this->assertTrue($first->ok);

        $spent = $this->ceiling()->spentMicrousd($business, AiUsageCategory::ContentAutopilot, $this->period());
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => $spent]);

        $second = app(AiGateway::class)->complete($this->request($workspace, $business));

        $this->assertFalse($second->ok);
        $this->assertSame(AiRefusalReason::CategoryCeilingReached, $second->refusalReason);
        $this->assertSame(1, $this->fake->callCount());
    }

    public function test_one_business_at_its_ceiling_does_not_block_another_or_another_category(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $other, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Studio', 'Other');
        $this->ledgerRow($workspace, $business, ['status' => 'committed', 'actual_cost_microusd' => 1_000_000]);

        $this->assertFalse(app(AiGateway::class)->complete($this->request($workspace, $business))->ok);
        $this->assertTrue(app(AiGateway::class)->complete($this->request($otherWorkspace, $other))->ok, 'another Business is unaffected');
        $this->assertTrue(app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration))->ok, 'other categories are unaffected');
    }

    public function test_the_autopilot_client_uses_the_cheap_route_to_assist_and_the_strong_route_to_write(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $client = app(\App\Library\Seo\Content\Autopilot\ContentAutopilotAiClient::class);
        $messages = [['role' => 'user', 'content' => 'hi']];

        $this->assertNotNull($client->assist($messages, $business, 'decision-1:outline', 50));
        $this->assertNotNull($client->write($messages, $business, 'decision-1:draft', 50));

        $rows = AiUsageLedgerEntry::query()->where('business_id', $business->id)->orderBy('id')->get(['category', 'model_route', 'idempotency_key']);
        $this->assertSame([AiUsageCategory::ContentAutopilot, AiUsageCategory::ContentAutopilot], $rows->pluck('category')->all());
        $this->assertSame([AiModelRoute::Routine, AiModelRoute::ContentWriter], $rows->pluck('model_route')->all());
        $this->assertSame(['decision-1:outline', 'decision-1:draft'], $rows->pluck('idempotency_key')->all());
    }

    public function test_the_autopilot_client_reports_a_ceiling_refusal_as_a_budget_refusal(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 0]);
        $client = app(\App\Library\Seo\Content\Autopilot\ContentAutopilotAiClient::class);

        $this->assertNull($client->write([['role' => 'user', 'content' => 'hi']], $business, 'decision-2:draft', 50));
        $this->assertSame(AiRefusalReason::CategoryCeilingReached, $client->lastRefusalReason());
        $this->assertTrue($client->lastCallWasBudgetRefusal());
        $this->assertSame(0, $this->fake->callCount());
    }
}
