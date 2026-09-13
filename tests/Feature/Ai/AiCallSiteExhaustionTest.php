<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * §11.4 at the call sites — Corrections 5, 6 and 8.
 *
 * A used-up allowance and a provider outage are different facts. One comes
 * back on a known date and asking again before then cannot help; the other
 * might clear in a minute. Telling a customer the wrong one is what these
 * tests exist to stop.
 */
class AiCallSiteExhaustionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);
    }

    // =================================================================
    // 6 — website: the refusal stays typed all the way to the customer
    // =================================================================

    public function test_the_website_client_keeps_the_refusal_reason_so_exhaustion_is_not_mistaken_for_an_outage(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->exhaust($workspace);

        $client = app(WebsiteAiGenerationClient::class);
        $raw = $client->complete([['role' => 'user', 'content' => 'draft']], $business, null);

        $this->assertNull($raw, 'The fail-closed contract is unchanged: no content.');
        $this->assertSame(AiRefusalReason::BudgetExhausted, $client->lastRefusalReason());
        $this->assertTrue($client->lastCallWasBudgetExhausted());
        $this->assertSame(0, $this->fakeClient->callCount(), 'A refusal never reaches the provider.');
    }

    public function test_a_provider_failure_is_reported_as_a_failure_and_not_as_an_exhausted_budget(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::failure());

        $client = app(WebsiteAiGenerationClient::class);
        $this->assertNull($client->complete([['role' => 'user', 'content' => 'draft']], $business, null));

        $this->assertNull($client->lastRefusalReason(), 'A provider failure is not a refusal.');
        $this->assertFalse($client->lastCallWasBudgetExhausted());
    }

    /**
     * The one bounded retry exists to correct a malformed response. Asking
     * again cannot make an allowance reappear, so an exhausted budget must
     * not burn a second refusal.
     */
    public function test_the_website_generator_does_not_retry_once_the_budget_is_exhausted(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteAiDraftGenerator.php'));

        $this->assertStringContainsString('if ($pages === null && ! $this->pausedByBudget) {', $source);
        $this->assertStringContainsString('lastCallWasBudgetExhausted()', $source);
        $this->assertStringContainsString('public function lastRunWasPausedByBudget(): bool', $source);
    }

    public function test_the_website_controller_says_paused_for_a_budget_and_unavailable_for_an_outage(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Customer/Business/WebsiteController.php'));

        $this->assertStringContainsString('lastRunWasPausedByBudget()', $source);
        $this->assertStringContainsString('AI drafting is paused until ', $source);
        $this->assertStringContainsString('You can keep editing your website.', $source);
        $this->assertStringContainsString('AI generation is currently unavailable.', $source, 'The outage sentence stays for the case it describes.');
    }

    // =================================================================
    // 5 — campaign: the button stays disabled with the right sentence
    // =================================================================

    public function test_the_campaign_draft_endpoint_reports_an_exhausted_budget_as_its_own_state(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->exhaust($workspace);
        $this->authenticateAs($customer);

        $response = $this->post(route('customer.openai.generate'), [
            'goal' => 'Fill Tuesday',
            'tone' => 'friendly',
            'audience' => 'regulars',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('budget_exhausted', true);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('AI drafting is paused until', $message);
        $this->assertStringContainsString('You can keep writing your message yourself.', $message);

        $this->assertSame(0, $this->fakeClient->callCount(), 'A refused draft never reaches the provider.');
    }

    public function test_an_ordinary_failure_is_not_reported_as_an_exhausted_budget(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::failure());

        $response = $this->post(route('customer.openai.generate'), [
            'goal' => 'Fill Tuesday',
            'tone' => 'friendly',
            'audience' => 'regulars',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('budget_exhausted', false);
    }

    /**
     * The builder must stop offering a retry that cannot succeed. Every
     * view that carries the draft modal does the same thing, so all of them
     * are checked rather than one.
     */
    public function test_every_campaign_builder_keeps_the_button_disabled_once_the_budget_is_exhausted(): void
    {
        $views = glob(resource_path('views/customer/Campaigns/*.blade.php')) ?: [];
        $views[] = resource_path('views/customer/Outreach/index.blade.php');
        $checked = 0;

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);

            if (! str_contains($source, "route('customer.openai.generate')")) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString('data.budget_exhausted', $source, basename($view) . ' must recognise the exhausted state.');
            $this->assertStringContainsString('aiBudgetExhausted = true;', $source, basename($view) . ' must remember it.');
            $this->assertMatchesRegularExpression(
                '/generateBtn\.prop\(\s*[\'"]disabled[\'"]\s*,\s*aiBudgetExhausted\s*\)/',
                $source,
                basename($view) . ' must leave the button disabled for that state.'
            );
            $this->assertStringNotContainsString('generateBtn.prop("disabled", false);', $source, basename($view) . ' must not unconditionally re-enable.');
        }

        $this->assertSame(13, $checked, 'Every view carrying the AI draft modal is covered.');
    }

    // =================================================================
    // 8 — prospecting idempotency is derived, never random
    // =================================================================

    public function test_the_prospecting_reply_key_is_derived_from_the_inbound_message(): void
    {
        $job = file_get_contents(app_path('Jobs/AgencyProspectingRespondJob.php'));

        $this->assertStringContainsString("'agency_prospect_reply:' . \$inbound->id", $job);
        $this->assertStringNotContainsString('Str::uuid()', $job, 'A random key would make every redelivery new work.');
    }

    public function test_the_same_inbound_message_answered_twice_reaches_the_provider_and_the_ledger_once(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $client = app(\App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient::class);
        $key = 'agency_prospect_reply:4242';

        $first = $client->complete([['role' => 'user', 'content' => 'Are you free?']], $workspace, null, $key);
        $this->assertNotNull($first);
        $this->assertSame(1, $this->fakeClient->callCount());

        $committedAfterFirst = (int) $this->period($workspace)->committed_microusd;
        $this->assertGreaterThan(0, $committedAfterFirst);

        // The queue redelivers the same job for the same inbound message.
        try {
            $client->complete([['role' => 'user', 'content' => 'Are you free?']], $workspace, null, $key);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The ledger's unique key rejected the duplicate inside its own
            // transaction, so the attempt rolled back whole.
        }

        $this->assertSame(1, $this->fakeClient->callCount(), 'The provider is reached at most once.');
        $this->assertSame($committedAfterFirst, (int) $this->period($workspace)->committed_microusd, 'And the budget charged at most once.');
        $this->assertSame(1, AiUsageLedgerEntry::query()->where('idempotency_key', $key)->count());
    }

    // -----------------------------------------------------------------

    /** Leave this Workspace's whole allowance spent for the period. */
    private function exhaust(Workspace $workspace): void
    {
        DB::table('ai_usage_periods')->insert([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => Carbon::now('UTC')->format('Y-m'),
            'policy_key' => 'growth',
            'policy_version' => 1,
            'cap_microusd' => 1,
            'reserved_microusd' => 0,
            'committed_microusd' => 1,
            'interactive_reserved_microusd' => 0,
            'interactive_committed_microusd' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function period(Workspace $workspace): object
    {
        return DB::table('ai_usage_periods')
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $workspace->id)
            ->first();
    }
}
