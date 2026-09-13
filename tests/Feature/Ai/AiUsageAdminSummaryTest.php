<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiUsageReadModel;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Slice AI-2 — the admin-only AI usage ledger summary (contract §10.3 C-9).
 *
 * Provider cost, tokens and model provenance belong to support and to nobody
 * else; the page reads them in bounded pages and never offers what AI-1 never
 * stored.
 */
class AiUsageAdminSummaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const PERIOD = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $this->ensureRequiredAppConfigRowsExist();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // Who can reach it
    // =================================================================

    public function test_a_guest_cannot_reach_the_admin_summary(): void
    {
        $this->get(route('admin.ai-usage.index'))->assertUnauthorized();
    }

    public function test_a_customer_cannot_reach_the_admin_summary_even_with_backend_permissions_in_session(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Agency);

        $this->authenticateAs($customer);
        $this->get(route('admin.ai-usage.index'))->assertUnauthorized();

        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);
        $this->get(route('admin.ai-usage.index'))->assertUnauthorized();
    }

    // =================================================================
    // What support sees
    // =================================================================

    public function test_an_administrator_sees_periods_and_ledger_provenance(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Provenance Client', 'Provenance Agency');
        $this->period($workspace, AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, 25_000_000, 1_234_567);
        $this->period($workspace, AiUsagePeriod::SCOPE_BUSINESS, (int) $business->id, 6_000_000, 1_234_567);
        $this->entry($workspace, $business, [
            'category' => 'website_generation',
            'model_route' => 'routine',
            'provider' => 'openai',
            'provider_model' => 'fixture-model-2026',
            'status' => 'committed',
            'input_tokens' => 4321,
            'cached_input_tokens' => 21,
            'output_tokens' => 654,
            'estimated_cost_microusd' => 9_876,
            'actual_cost_microusd' => 1_234,
            'idempotency_key' => 'website:fixture-provenance',
        ]);
        $this->entry($workspace, null, [
            'category' => 'agency_prospect_reply',
            'status' => 'refused',
            'refusal_reason' => 'budget_exhausted',
        ]);

        $this->actingAsAdmin();
        $html = html_entity_decode((string) $this->get(route('admin.ai-usage.index'))->assertOk()->getContent(), ENT_QUOTES);

        foreach ([
            'Provenance Agency', 'Provenance Client', 'agency',            // account, business, policy
            '$25.000000', '$6.000000', '$1.234567',                    // cap and committed, admin precision
            'website_generation', 'routine', 'openai', 'fixture-model-2026',
            '4321 / 21 / 654',                                          // tokens
            '$0.009876', '$0.001234',                                   // estimated and actual cost
            'committed', 'refused', 'budget_exhausted', 'agency_prospect_reply',
            'website:fixture-provenance',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "The admin summary should show {$expected}.");
        }
    }

    public function test_the_ledger_is_read_in_bounded_pages_never_as_a_dump(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        for ($i = 0; $i < 60; $i++) {
            $this->entry($workspace, $business, ['status' => 'committed']);
        }

        $this->actingAsAdmin();

        $first = (string) $this->get(route('admin.ai-usage.index'))->assertOk()->getContent();
        $second = (string) $this->get(route('admin.ai-usage.index', ['ledger_page' => 2]))->assertOk()->getContent();

        $this->assertSame(50, substr_count($first, 'data-role="ai-usage-ledger-row"'), 'One page is 50 entries.');
        $this->assertSame(10, substr_count($second, 'data-role="ai-usage-ledger-row"'), 'The rest are on the next page.');

        // And no caller can ask for more than the ceiling.
        $page = app(AiUsageReadModel::class)->adminLedger(['period_key' => self::PERIOD], 100_000);
        $this->assertSame(AiUsageReadModel::ADMIN_MAX_PER_PAGE, $page->perPage());
        $this->assertSame(AiUsageReadModel::ADMIN_MAX_PER_PAGE, app(AiUsageReadModel::class)->adminPeriods(['period_key' => self::PERIOD], 100_000)->perPage());
    }

    public function test_filters_narrow_the_ledger_and_unknown_values_are_ignored(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Filter One', 'Filter Account One');
        [, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Filter Two', 'Filter Account Two');

        $this->entry($workspace, $business, ['status' => 'committed', 'category' => 'website_generation']);
        $this->entry($workspace, $business, ['status' => 'refused', 'refusal_reason' => 'budget_exhausted', 'category' => 'campaign_message_draft']);
        $this->entry($otherWorkspace, $otherBusiness, ['status' => 'committed', 'category' => 'website_generation']);
        $this->entry($workspace, $business, ['status' => 'committed', 'period_key' => '2026-08']);

        $this->actingAsAdmin();
        $rows = fn (array $query): int => substr_count((string) $this->get(route('admin.ai-usage.index', $query))->assertOk()->getContent(), 'data-role="ai-usage-ledger-row"');

        $this->assertSame(3, $rows([]), 'The current period only.');
        $this->assertSame(1, $rows(['period' => '2026-08']));
        $this->assertSame(1, $rows(['status' => 'refused']));
        $this->assertSame(1, $rows(['category' => 'campaign_message_draft']));
        $this->assertSame(2, $rows(['workspace_id' => $workspace->id]));
        $this->assertSame(3, $rows(['status' => 'not-a-status', 'category' => 'drop table', 'workspace_id' => 'x']), 'Garbage is not a filter.');
        $this->assertSame(3, $rows(['period' => "2026-09' OR 1=1"]), 'A malformed period falls back to the current one.');
    }

    /**
     * Support reaches it from the Usage Billing admin menu, under a label that
     * resolves — never a raw `locale.menu.*` key.
     */
    public function test_the_admin_menu_links_to_the_summary_with_a_translated_label(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->entry($workspace, $business);
        $this->actingAsAdmin();

        $html = (string) $this->get(route('admin.ai-usage.index'))->assertOk()->getContent();

        $this->assertTrue(\Illuminate\Support\Facades\Lang::has('locale.menu.AI Usage', 'en'));
        $this->assertStringContainsString(url(config('app.admin_path') . '/ai-usage'), $html);
        $this->assertStringNotContainsString('locale.menu.AI Usage', $html);
    }

    public function test_nothing_the_ledger_never_stored_can_be_shown(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('ai_usage_ledger'));

        foreach (['prompt', 'response', 'messages', 'content', 'completion', 'body'] as $absent) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($absent, $column, "AI-1 persists no {$absent}; the ledger has no such column.");
            }
        }

        // The admin read names its columns, so a column added later is not
        // shown by accident.
        $source = (string) file_get_contents(app_path('Library/Ai/AiUsageReadModel.php'));
        $this->assertStringContainsString('ADMIN_LEDGER_COLUMNS', $source);
        $this->assertStringNotContainsString("'ai_usage_ledger.*'", $source);
    }

    // -----------------------------------------------------------------

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'first_name' => 'AI2', 'last_name' => 'Admin',
            'email' => 'ai2admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    private function period(Workspace $workspace, string $scope, int $scopeId, int $cap, int $committed): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'workspace_id' => $workspace->id,
            'period_key' => self::PERIOD,
            'policy_key' => 'agency',
            'policy_version' => 1,
            'cap_microusd' => $cap,
            'reserved_microusd' => 0,
            'committed_microusd' => $committed,
            'interactive_reserved_microusd' => 0,
            'interactive_committed_microusd' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function entry(Workspace $workspace, ?Business $business, array $overrides = []): void
    {
        DB::table('ai_usage_ledger')->insert(array_merge([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'business_id' => $business?->id,
            'category' => 'website_generation',
            'lane' => 'product',
            'model_route' => 'routine',
            'provider' => 'openai',
            'provider_model' => null,
            'price_version' => 1,
            'status' => 'committed',
            'refusal_reason' => null,
            'input_tokens' => 10,
            'cached_input_tokens' => 0,
            'output_tokens' => 5,
            'estimated_cost_microusd' => 100,
            'actual_cost_microusd' => 50,
            'period_key' => self::PERIOD,
            'idempotency_key' => 'ai2-admin-fixture:' . Str::uuid(),
            'actor_user_id' => null,
            'created_at' => Carbon::now(),
            'settled_at' => Carbon::now(),
        ], $overrides));
    }
}
