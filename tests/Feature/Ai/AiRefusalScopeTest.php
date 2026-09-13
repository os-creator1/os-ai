<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiRefusalScope;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Workspace;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * AI-2 correction — the limiting scope of a budget refusal is decided at the
 * authoritative refusal point (AiUsageLedgerManager::reserve()), written to
 * the existing ledger, and read by Settings -> Billing -> AI usage.
 *
 * Every case here goes through the real AiGateway with the real reservation
 * transaction; only the provider is faked, and a refused call never reaches it.
 */
class AiRefusalScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const LIMIT = "This month's included AI is used up. Your Home, results and automations keep working; AI summaries return on 1 October.";

    private const NORMAL = 'Included AI usage — Normal.';

    private const PERIOD = '2026-09';

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // The authoritative refusal point records whose allowance was the limit
    // =================================================================

    public function test_an_agency_business_at_its_own_cap_is_refused_with_business_scope(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Own Cap Client', 'Own Cap Agency');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), 1_000);
        $this->openPeriod(AiUsagePeriod::SCOPE_BUSINESS, (int) $business->id, $workspace, $this->businessCap(), $this->businessCap());

        $entry = $this->refusedCall($workspace, $business);

        $this->assertSame(AiRefusalReason::BudgetExhausted, $entry->refusal_reason, 'The reason callers see is unchanged.');
        $this->assertSame(AiRefusalScope::Business, $entry->refusal_scope);
    }

    /**
     * The case the correction exists for: committed is still just under the
     * Workspace cap, the next request does not fit, and the call carried a
     * Business with plenty of its own allowance left.
     */
    public function test_a_business_scoped_call_refused_by_the_workspace_cap_is_recorded_as_workspace_scope(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Headroom Client', 'Headroom Agency');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $this->workspaceCap() - 1);
        $this->openPeriod(AiUsagePeriod::SCOPE_BUSINESS, (int) $business->id, $workspace, $this->businessCap(), 1_000);

        $entry = $this->refusedCall($workspace, $business);

        $this->assertSame((int) $business->id, (int) $entry->business_id, 'A Business-scoped call ...');
        $this->assertSame(AiRefusalScope::Workspace, $entry->refusal_scope, '... refused by the Workspace allowance.');
    }

    public function test_a_call_both_caps_would_refuse_names_both(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Both Client', 'Both Agency');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $this->workspaceCap());
        $this->openPeriod(AiUsagePeriod::SCOPE_BUSINESS, (int) $business->id, $workspace, $this->businessCap(), $this->businessCap());

        $this->assertSame(AiRefusalScope::WorkspaceAndBusiness, $this->refusedCall($workspace, $business)->refusal_scope);
    }

    public function test_an_agency_workspace_level_call_is_refused_with_workspace_scope(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Prospecting Client', 'Prospecting Agency');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $this->workspaceCap());

        $entry = $this->refusedCall($workspace, null, AiUsageCategory::AgencyProspectReply);

        $this->assertNull($entry->business_id);
        $this->assertSame(AiRefusalScope::Workspace, $entry->refusal_scope);
    }

    public function test_one_allowance_is_always_the_workspace_scope(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $cap = (int) config('ai.budgets.growth.workspace_cap_microusd');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $cap, $cap, 'growth');

        $this->assertSame(AiRefusalScope::Workspace, $this->refusedCall($workspace, $business)->refusal_scope);
    }

    public function test_the_interactive_share_alone_keeps_its_own_reason_and_scope(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Chat Client', 'Chat Agency');
        $interactiveCap = intdiv($this->workspaceCap() * (int) config('ai.budgets.agency.interactive_share_bps'), 10_000);
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $interactiveCap, 'agency', $interactiveCap);

        $entry = $this->refusedCall($workspace, null, AiUsageCategory::CampaignMessageDraft, AiLane::Interactive);

        $this->assertSame(AiRefusalReason::InteractiveShareExhausted, $entry->refusal_reason);
        $this->assertSame(AiRefusalScope::InteractiveShare, $entry->refusal_scope);
    }

    public function test_a_call_that_is_not_refused_records_no_scope(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Fine Client', 'Fine Agency');

        $result = app(AiGateway::class)->complete($this->request($workspace, $business));

        $this->assertTrue($result->ok);
        $this->assertNull($result->ledgerEntry->fresh()->refusal_scope);
        $this->assertSame(AiUsageEntryStatus::Committed, $result->ledgerEntry->fresh()->status);
    }

    // =================================================================
    // AI-2 reads it — the Agency cases end to end
    // =================================================================

    public function test_a_business_at_its_own_cap_is_limit_reached_on_its_row_and_not_on_the_account(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Row Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), intdiv($this->workspaceCap(), 10));
        // Own allowance spent by all but one micro-unit: committed says
        // "nearing"; only the recorded refusal can say "reached".
        $this->openPeriod(AiUsagePeriod::SCOPE_BUSINESS, (int) $second->id, $workspace, $this->businessCap(), $this->businessCap() - 1);

        $this->refusedCall($workspace, $second);

        $this->authenticateAs($owner);
        $section = $this->section($workspace, $first);

        $this->assertStringContainsString(self::NORMAL, $this->text($section), 'The account headline follows the Workspace allowance.');
        $this->assertSame('limit_reached', $this->rowStates($section)[$second->uid]);
        $this->assertSame('normal', $this->rowStates($section)[$first->uid]);
    }

    public function test_the_workspace_cap_refusing_a_business_scoped_call_makes_the_account_limit_reached(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Headline Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $this->workspaceCap() - 1);
        $this->openPeriod(AiUsagePeriod::SCOPE_BUSINESS, (int) $second->id, $workspace, $this->businessCap(), intdiv($this->businessCap(), 5));

        $this->refusedCall($workspace, $second);

        $this->authenticateAs($owner);
        $section = $this->section($workspace, $first);

        $this->assertStringContainsString(self::LIMIT, $this->text($section), 'Committed is one micro-unit under the cap, and the account is used up.');
        $this->assertSame('normal', $this->rowStates($section)[$second->uid], 'Client Two\'s own allowance is at 20%; its row stays truthful.');
    }

    public function test_an_agency_workspace_level_refusal_makes_the_account_limit_reached(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Workspace Level Agency');
        $this->openPeriod(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $this->workspaceCap(), $this->workspaceCap() - 1);

        $this->refusedCall($workspace, null, AiUsageCategory::AgencyProspectReply);

        $this->authenticateAs($owner);
        $this->assertStringContainsString(self::LIMIT, $this->text($this->section($workspace, $first)));
    }

    // =================================================================
    // The migration is additive and old rows stay readable
    // =================================================================

    public function test_the_scope_column_is_nullable_and_a_row_without_one_still_reads(): void
    {
        $this->assertTrue(Schema::hasColumn('ai_usage_ledger', 'refusal_scope'));

        $column = collect(Schema::getColumns('ai_usage_ledger'))->firstWhere('name', 'refusal_scope');
        $this->assertTrue((bool) $column['nullable'], 'Additive: existing rows need no value.');
        $this->assertNull($column['default']);

        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $id = DB::table('ai_usage_ledger')->insertGetId([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id,
            'category' => 'website_generation', 'lane' => 'product', 'model_route' => 'routine', 'provider' => 'openai',
            'price_version' => 1, 'status' => 'refused', 'refusal_reason' => 'budget_exhausted',
            'estimated_cost_microusd' => 10, 'period_key' => self::PERIOD, 'idempotency_key' => 'legacy:' . Str::uuid(),
            'created_at' => Carbon::now(), 'settled_at' => Carbon::now(),
        ]);

        $legacy = AiUsageLedgerEntry::query()->findOrFail($id);
        $this->assertNull($legacy->refusal_scope);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $legacy->refusal_reason);
    }

    public function test_the_migration_rolls_back_cleanly(): void
    {
        $migration = require database_path('migrations/2026_09_18_100001_add_refusal_scope_to_ai_usage_ledger_table.php');

        $source = (string) file_get_contents(database_path('migrations/2026_09_18_100001_add_refusal_scope_to_ai_usage_ledger_table.php'));
        $this->assertStringContainsString("->nullable()", $source);
        $this->assertStringContainsString("dropColumn('refusal_scope')", $source);
        $this->assertStringNotContainsString('->update(', $source, 'No backfill.');
        $this->assertTrue(method_exists($migration, 'down'));
    }

    // -----------------------------------------------------------------

    private function refusedCall(Workspace $workspace, ?Business $business, AiUsageCategory $category = AiUsageCategory::WebsiteGeneration, AiLane $lane = AiLane::Product): AiUsageLedgerEntry
    {
        $result = app(AiGateway::class)->complete($this->request($workspace, $business, $category, $lane));

        $this->assertFalse($result->ok, 'The call must be refused.');
        $this->assertSame(0, $this->fakeClient->callCount(), 'A refused call never reaches the provider.');
        $this->assertNotNull($result->ledgerEntry);

        return $result->ledgerEntry->fresh();
    }

    private function request(Workspace $workspace, ?Business $business, AiUsageCategory $category = AiUsageCategory::WebsiteGeneration, AiLane $lane = AiLane::Product): AiRequest
    {
        return new AiRequest(
            workspace: $workspace,
            business: $business,
            category: $category,
            lane: $lane,
            route: app(AiModelRouter::class)->defaultRouteFor($category),
            messages: [['role' => 'user', 'content' => 'hello world']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
        );
    }

    private function workspaceCap(): int
    {
        return (int) config('ai.budgets.agency.workspace_cap_microusd');
    }

    private function businessCap(): int
    {
        return (int) config('ai.budgets.agency.business_cap_microusd');
    }

    private function openPeriod(string $scope, int $scopeId, Workspace $workspace, int $cap, int $committed, string $policy = 'agency', int $interactiveCommitted = 0): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => $scope, 'scope_id' => $scopeId, 'workspace_id' => $workspace->id,
            'period_key' => self::PERIOD, 'policy_key' => $policy, 'policy_version' => 1,
            'cap_microusd' => $cap, 'reserved_microusd' => 0, 'committed_microusd' => $committed,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => $interactiveCommitted,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function section(Workspace $workspace, Business $business): string
    {
        $html = (string) $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $node = (new DOMXPath($dom))->query('//*[@id="usage-billing-ai-usage"]')->item(0);
        $this->assertNotNull($node, 'The AI usage section renders.');

        return (string) $dom->saveHTML($node);
    }

    private function text(string $fragment): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return array<string, string> */
    private function rowStates(string $section): array
    {
        preg_match_all('/data-role="ai-usage-business-row" data-business-uid="([^"]+)" data-state="([^"]+)"/', $section, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->all();
    }
}
