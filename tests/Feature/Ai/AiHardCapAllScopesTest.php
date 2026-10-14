<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\AiUsagePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Correction round 1, item 6: every plan cap (trial $3, Core $5, Growth $10, Agency $25 / $6 per Business) and the
 * Platform's own cap are HARD for every category — with the retired observation flag explicitly OFF.
 */
class AiHardCapAllScopesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fake;

    private const LEGACY = [AiUsageCategory::WebsiteGeneration, AiUsageCategory::CampaignMessageDraft, AiUsageCategory::AgencyProspectReply];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        config(['services.openai.active' => true, 'ai.enforce_budgets_for_existing_categories' => false]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request($workspace, $business, AiUsageCategory $category, string $content = 'x'): AiRequest
    {
        return AiRequest::forWorkspace($workspace, $business, $category, AiLane::Product, AiModelRoute::Routine, [['role' => 'user', 'content' => $content]], 200, (string) Str::uuid(), null);
    }

    private function period($workspace, string $key, string $policy, int $cap, int $committed, string $scope = 'workspace', ?int $scopeId = null): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => $scope, 'scope_id' => $scopeId ?? $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => $key, 'policy_key' => $policy,
            'policy_version' => 1, 'cap_microusd' => $cap, 'reserved_microusd' => 0, 'committed_microusd' => $committed,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return iterable<string, array{0: WorkspacePlanTier, 1: ?int, 2: string, 3: int}> */
    public static function plans(): iterable
    {
        yield 'trial $3' => [WorkspacePlanTier::Growth, 3_000_000, 'trial', 3_000_000];
        yield 'core $5' => [WorkspacePlanTier::Core, null, '2026-09', 5_000_000];
        yield 'growth $10' => [WorkspacePlanTier::Growth, null, '2026-09', 10_000_000];
        yield 'agency $25' => [WorkspacePlanTier::Agency, null, '2026-09', 25_000_000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_a_plan_cap_is_hard_for_every_legacy_category_with_the_flag_off(WorkspacePlanTier $tier, ?int $trialCap, string $periodKey, int $cap): void
    {
        [, $business, $workspace] = $this->tenant($tier, 'Cap Venue', 'Cap Account');

        if ($trialCap !== null) {
            DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['trial_ends_at' => now()->addDays(5), 'is_complimentary' => false]);
            app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        }

        $this->period($workspace, $periodKey, $trialCap !== null ? 'trial' : $tier->value, $cap, $cap);

        foreach (self::LEGACY as $category) {
            $r = app(AiGateway::class)->complete($this->request($workspace, $business, $category));
            $this->assertSame(AiRefusalReason::BudgetExhausted, $r->refusalReason, "{$category->value} over the {$tier->value} cap");
        }

        $this->assertSame(0, $this->fake->callCount());
        $this->assertSame($cap, (int) AiUsagePeriod::query()->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->value('committed_microusd'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_the_boundary_reservation_fits_exactly_and_one_more_does_not(WorkspacePlanTier $tier, ?int $trialCap, string $periodKey, int $cap): void
    {
        [, $business, $workspace] = $this->tenant($tier, 'Edge Venue', 'Edge Account');

        if ($trialCap !== null) {
            DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['trial_ends_at' => now()->addDays(5), 'is_complimentary' => false]);
            app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        }

        $request = $this->request($workspace, $business, AiUsageCategory::WebsiteGeneration);
        $estimate = app(\App\Library\Ai\AiModelRouter::class)->estimateCostMicrousd(AiModelRoute::Routine, $request->messages, 200);
        $policy = $trialCap !== null ? 'trial' : $tier->value;
        $this->period($workspace, $periodKey, $policy, $cap, $cap - $estimate); // exactly one estimate of room

        $this->fake->setDefaultResult(AiCompletionResult::success('ok', 'gpt-4o-mini', 100, 10));
        $first = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration));
        $this->assertTrue($first->ok, 'Fits at the boundary.');

        // After the first call settles at its (much smaller) real cost there is room again; fill it to the brim.
        DB::table('ai_usage_periods')->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->update(['committed_microusd' => $cap - $estimate + 1]);
        $second = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration));
        $this->assertSame(AiRefusalReason::BudgetExhausted, $second->refusalReason, 'One microusd short is refused.');
    }

    public function test_the_agency_per_business_cap_is_hard_too(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Venue', 'Agency Account');
        $this->period($workspace, '2026-09', 'agency', 25_000_000, 0);
        $this->period($workspace, '2026-09', 'agency', 6_000_000, 6_000_000, 'business', (int) $business->id);

        $r = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::CampaignMessageDraft));

        $this->assertSame(AiRefusalReason::BudgetExhausted, $r->refusalReason);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_the_platform_scope_cap_is_hard_with_the_flag_off(): void
    {
        $admin = User::create(['first_name' => 'Platform', 'last_name' => 'Admin', 'email' => 'cap-admin-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        config(['ai.platform.monthly_cap_microusd' => 1_000]);
        $make = fn () => AiRequest::forPlatform($admin->id, AiUsageCategory::AgencyProspectReply, AiLane::Product, AiModelRoute::Routine, [['role' => 'user', 'content' => 'hello']], 200, (string) Str::uuid());
        $this->fake->setDefaultResult(AiCompletionResult::success('ok', 'gpt-4o-mini', 6_000, 200));

        $accepted = 0;
        for ($i = 0; $i < 20; $i++) {
            $r = app(AiGateway::class)->complete($make());
            $accepted += $r->ok ? 1 : 0;
            if (! $r->ok) {
                $this->assertSame(AiRefusalReason::BudgetExhausted, $r->refusalReason);
            }
        }

        $period = AiUsagePeriod::query()->where('scope_type', 'platform')->firstOrFail();
        $this->assertGreaterThan(0, $accepted);
        $this->assertLessThan(20, $accepted, 'Platform AI stops at its own finite cap.');
        $this->assertLessThanOrEqual(1_000, (int) $period->committed_microusd + (int) $period->reserved_microusd);
        $this->assertSame($accepted, $this->fake->callCount());

        config(['ai.platform.monthly_cap_microusd' => 0]);
        $this->assertSame(AiRefusalReason::BudgetExhausted, app(AiGateway::class)->complete($make())->refusalReason, 'An absent cap fails closed.');
    }

    public function test_no_code_path_still_reads_the_observation_flag(): void
    {
        foreach (['app/Library/Ai/AiGateway.php', 'app/Library/Ai/AiUsageLedgerManager.php'] as $file) {
            $this->assertStringNotContainsString("config('ai.enforce_budgets_for_existing_categories')", (string) file_get_contents(base_path($file)), $file);
        }
    }
}
