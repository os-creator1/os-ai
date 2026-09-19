<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Coo\GenerateCooInsight;
use App\Library\Ai\AiGateway;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Coo\Insight\CooInsightDisplayReader;
use App\Library\Coo\Insight\CooInsightExplainLimiter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * AI-3 — "What we notice" on Business performance (§2.5), the §9.2
 * displayability matrix (T-INS-3), and Home's zero-AI guarantee (T-COO-1).
 */
class BusinessHomeWhatWeNoticeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
        config(['opportunity.enabled' => true]);
    }

    // =================================================================
    // Rendering
    // =================================================================

    public function test_without_a_cached_insight_there_is_no_ai_line_at_all(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-band="headlines"', $html);
        $this->assertStringNotContainsString('data-role="what-we-notice"', $html);
        $this->assertStringNotContainsString('AI summary', $html);
    }

    public function test_a_valid_cached_insight_is_labelled_ai_summary_with_plain_certainty_words_and_its_date(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business);
        $this->authenticateAs($customer);

        $block = $this->whatWeNotice($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('What we notice', $block);
        $this->assertStringContainsString('AI summary', $block);
        $this->assertStringContainsString('Updated 9 Sep 2026', $block);
        $this->assertStringContainsString('Known:', $block, 'Certainty is a word, never a colour alone (§8.4).');
        $this->assertStringContainsString('Likely:', $block);
        $this->assertStringContainsString('New contacts rose to 20, from 5 in the ten days before.', html_entity_decode($block, ENT_QUOTES));
    }

    public function test_statement_text_is_escaped_never_rendered_as_html(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business, ['output' => ['statements' => [['class' => 'unknown', 'text' => 'Nothing <b>bold</b> here.', 'fact_refs' => []]]]]);
        $this->authenticateAs($customer);

        $block = $this->whatWeNotice($this->home()->getContent());

        $this->assertStringNotContainsString('<b>bold</b>', $block);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $block);
    }

    public function test_malformed_stored_output_renders_nothing_rather_than_part_of_it(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business, ['output' => ['statements' => [
            ['class' => 'known', 'text' => 'Fine.', 'fact_refs' => []],
            ['class' => 'certain', 'text' => 'Not a class the contract allows.', 'fact_refs' => []],
        ]]]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent());
    }

    // =================================================================
    // T-INS-3 — displayability
    // =================================================================

    public function test_an_invalidated_insight_is_never_shown(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business, ['invalidated_at' => Carbon::now()->subHour(), 'invalidation_reason' => 'signal_changed']);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent());

        config(['services.openai.active' => false]);
        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent(), 'Not even while AI is off.');
    }

    public function test_an_expired_insight_is_shown_only_while_it_cannot_be_replaced(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business, ['generated_at' => Carbon::now()->subDays(9), 'expires_at' => Carbon::now()->subDays(2)]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent(), 'AI on, budget available: an expired answer is not presented as current.');

        config(['services.openai.active' => false]);
        $this->assertStringContainsString('data-role="what-we-notice"', $this->home()->getContent(), 'AI off: cached AI stays visible (L-12).');
        $this->assertStringContainsString('Updated 1 Sep 2026', $this->home()->getContent());

        config(['services.openai.active' => true]);
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'workspace_id' => $workspace->id,
            'period_key' => Carbon::now('UTC')->format('Y-m'), 'policy_key' => 'growth', 'policy_version' => 1,
            'cap_microusd' => 1, 'reserved_microusd' => 0, 'committed_microusd' => 1,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 0,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $this->assertStringContainsString('data-role="what-we-notice"', $this->home()->getContent(), 'Included AI used up: still visible.');
    }

    public function test_an_insight_for_another_window_or_an_older_version_is_never_selected(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->cachedInsight($business, ['period_key' => 'last_30_days']);
        $this->cachedInsight($business, ['prompt_version' => (int) config('coo.insight.prompt_version') + 1, 'signal_fingerprint' => str_repeat('a', 64)]);
        $this->cachedInsight($business, ['policy_version' => (int) config('coo.insight.policy_version') + 1, 'signal_fingerprint' => str_repeat('b', 64)]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent());
        $this->assertStringContainsString('data-role="what-we-notice"', $this->home(['range' => 'last_30_days'])->getContent(), 'It shows under the window it explains.');
    }

    public function test_losing_ai_coo_basic_stops_the_line_while_the_row_stays(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $insight = $this->cachedInsight($business);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::AiCooBasic, WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'AI-3 fixture.');
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="what-we-notice"', $this->home()->getContent());
        $this->assertNull($insight->fresh()->invalidated_at, 'Rows stay for audit.');
    }

    public function test_a_failing_insight_read_is_the_same_as_no_insight_never_an_error_card(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        // An expired row sends the reader down its budget branch, and a broken
        // policy config makes that branch throw for real inside the reader.
        $this->cachedInsight($business, ['generated_at' => Carbon::now()->subDays(9), 'expires_at' => Carbon::now()->subDays(2)]);
        config(['ai.budgets.growth' => null]);
        $this->authenticateAs($customer);

        $fresh = $business->fresh();

        try {
            app(CooInsightDisplayReader::class)->forHome($fresh, $this->thisMonth($business), $this->actorEnvelope($fresh, $customer->user));
            $this->fail('The reader was expected to throw in this fixture.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            $this->assertNotInstanceOf(\ArgumentCountError::class, $e, 'The reader must throw for the reason this fixture sets up, not because it was called wrongly.');
        }

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-band="headlines"', $html, 'Business performance still renders.');
        $this->assertStringNotContainsString('data-role="what-we-notice"', $html);
        $this->assertStringNotContainsString('band-failed', $this->bandHtml($html, 'headlines'));
    }

    // =================================================================
    // T-COO-1 — Home never calls AI
    // =================================================================

    public function test_home_makes_zero_provider_calls_zero_gateway_resolutions_zero_ledger_rows_and_queues_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);
        $this->authenticateAs($customer);

        $resolved = [];
        $this->app->resolving(AiGateway::class, function () use (&$resolved): void {
            $resolved[] = AiGateway::class;
        });
        $this->app->bind(AiCompletionClient::class, function () use (&$resolved) {
            $resolved[] = AiCompletionClient::class;

            return $this->fakeAi;
        });

        Queue::fake();

        foreach ([false, true] as $withInsight) {
            if ($withInsight) {
                $this->cachedInsight($business);
            }

            for ($i = 0; $i < 3; $i++) {
                Cache::forget('coo_insight:explain:business:' . $business->id);
                $this->home()->assertOk();
                $this->home(['range' => 'last_30_days'])->assertOk();
            }
        }

        $this->assertSame([], $resolved, 'Home resolves no AI gateway and no AI client.');
        $this->assertSame(0, $this->fakeAi->callCount(), 'Zero provider calls.');
        $this->assertSame(0, AiUsageLedgerEntry::query()->count(), 'Zero reservations, zero ledger rows.');
        $this->assertSame(0, DB::table('ai_usage_periods')->count(), 'Not even a budget period opened.');
        Queue::assertNotPushed(GenerateCooInsight::class);
        Queue::assertNothingPushed();
    }

    public function test_the_next_best_move_and_why_this_are_unchanged_by_an_insight(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->recommendation($business, ['type' => 'missing_phone', 'evidence' => json_encode([['source_type' => 'business_profile', 'fact_key' => 'phone_blank', 'summary' => 'The business phone number is not set.']])]);
        $this->authenticateAs($customer);

        Cache::flush();
        $before = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_NEXT_BEST_MOVE);
        $htmlBefore = $this->bandHtml($this->home()->getContent(), 'next_best_move');

        $this->cachedInsight($business);

        Cache::flush();
        $after = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_NEXT_BEST_MOVE);
        $htmlAfter = $this->bandHtml($this->home()->getContent(), 'next_best_move');

        $this->assertEquals($before, $after, 'C-2 is deterministic and knows nothing of AI.');
        $this->assertSame($htmlBefore, $htmlAfter);
        $this->assertStringNotContainsString('AI summary', $htmlAfter, '"Why this?" never merges the AI line.');
    }

    // =================================================================
    // E-4 control
    // =================================================================

    public function test_explain_this_change_is_offered_only_when_there_is_a_change_and_an_answer_is_possible(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="explain-button"', $this->home()->getContent(), 'Nothing moved materially: nothing to explain.');

        $this->materialPeriod($business);
        Cache::flush();
        $html = $this->home()->getContent();
        $this->assertStringContainsString('data-role="explain-button"', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.performance.explain', [$workspace->uid, $business->uid]), $html);
        $this->assertMatchesRegularExpression('/name="range" value="this_month"/', $html);

        config(['services.openai.active' => false]);
        $this->assertStringNotContainsString('data-role="explain-button"', $this->home()->getContent(), 'AI off.');
        config(['services.openai.active' => true]);

        app(CooInsightExplainLimiter::class)->claim((int) $business->id);
        $html = $this->home()->getContent();
        $this->assertStringNotContainsString('data-role="explain-button"', $html);
        $this->assertStringContainsString('data-role="explain-requested"', $html, 'Already asked in the window: said, not offered again.');
        Cache::flush();

        $this->cachedInsight($business);
        $this->assertStringNotContainsString('data-role="explain-this-change"', $this->home()->getContent(), 'An insight already explains this window.');
    }

    public function test_explain_this_change_is_never_offered_while_viewing_as_a_client(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->materialPeriod($viewed);
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="view-as-banner"', $html);
        $this->assertStringNotContainsString('data-role="explain-button"', $html);
    }

    // -----------------------------------------------------------------

    /** @param array<string, string> $query */
    protected function home(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('user.home', $query));
    }

    private function whatWeNotice(string $html): string
    {
        $start = strpos($html, 'data-role="what-we-notice"');
        $this->assertNotFalse($start, 'The "What we notice" block renders.');

        return substr($html, $start, 3000);
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, $end === false ? null : $end - $start);
    }

}
