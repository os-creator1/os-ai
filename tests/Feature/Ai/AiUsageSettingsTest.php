<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Ai\AiBudgetPolicy;
use App\Library\Ai\AiUsagePresenter;
use App\Library\Ai\AiUsageStanding;
use App\Library\Ai\Enums\AiUsageState;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Workspace;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Slice AI-2 — Settings -> Billing -> AI usage (contract §11.3, T-UX-AI-1/2).
 *
 * What the customer reads about AI-1's ledger is a state and a sentence, for
 * the people allowed to see billing, and never a figure.
 */
class AiUsageSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const PERIOD = '2026-09';

    private const NORMAL = 'Included AI usage — Normal.';

    private const NEARING = "You've used most of this month's included AI. Everything else keeps working.";

    private const LIMIT = "This month's included AI is used up. Your Home, results and automations keep working; AI summaries return on 1 October.";

    private const LIMIT_TRIAL = "Your trial's included AI is used up. Everything else keeps working.";

    private const TRIAL_LINE = 'Your trial includes a smaller AI allowance.';

    protected function setUp(): void
    {
        parent::setUp();

        // One fixed UTC period for every assertion: 2026-09, next period 1 October.
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // The state rule, at its exact boundaries
    // =================================================================

    public function test_the_states_change_exactly_at_the_configured_threshold_and_at_the_cap(): void
    {
        config(['ai.presentation.nearing_limit_bps' => 8000]);
        $presenter = app(AiUsagePresenter::class);
        $cap = 1_000_000;

        $this->assertSame(AiUsageState::Normal, $presenter->stateFor(new AiUsageStanding(0, $cap, false)));
        $this->assertSame(AiUsageState::Normal, $presenter->stateFor(new AiUsageStanding(799_999, $cap, false)), 'One micro-unit under 80% is still normal.');
        $this->assertSame(AiUsageState::NearingLimit, $presenter->stateFor(new AiUsageStanding(800_000, $cap, false)), 'Exactly 80% is nearing.');
        $this->assertSame(AiUsageState::NearingLimit, $presenter->stateFor(new AiUsageStanding(999_999, $cap, false)), 'Just under the cap is nearing.');
        $this->assertSame(AiUsageState::LimitReached, $presenter->stateFor(new AiUsageStanding(1_000_000, $cap, false)), 'Exactly the cap is used up.');
        $this->assertSame(AiUsageState::LimitReached, $presenter->stateFor(new AiUsageStanding(1_400_000, $cap, false)), 'Observation mode can run past the cap; still used up.');
    }

    public function test_a_budget_refusal_this_period_means_the_limit_was_reached_whatever_committed_says(): void
    {
        $presenter = app(AiUsagePresenter::class);

        $this->assertSame(AiUsageState::LimitReached, $presenter->stateFor(new AiUsageStanding(10, 1_000_000, true)));
    }

    public function test_the_threshold_is_configuration_not_a_second_literal(): void
    {
        $presenter = app(AiUsagePresenter::class);
        $half = new AiUsageStanding(500_000, 1_000_000, false);

        config(['ai.presentation.nearing_limit_bps' => 8000]);
        $this->assertSame(AiUsageState::Normal, $presenter->stateFor($half));

        config(['ai.presentation.nearing_limit_bps' => 5000]);
        $this->assertSame(AiUsageState::NearingLimit, $presenter->stateFor($half), 'Moving the config moves the state.');

        $source = (string) file_get_contents(app_path('Library/Ai/AiUsagePresenter.php'));
        $this->assertStringContainsString("config('ai.presentation.nearing_limit_bps')", $source);
        $this->assertStringNotContainsString('8000', $source, 'The threshold lives in config/ai.php only.');
        $this->assertStringNotContainsString('0.8', $source);
        $this->assertStringContainsString("'nearing_limit_bps' => (int) env('AI_PRESENTATION_NEARING_LIMIT_BPS', 8000)", (string) file_get_contents(config_path('ai.php')), "The contract's 0.8, once, in config.");
    }

    // =================================================================
    // Exact §11.3 copy
    // =================================================================

    public function test_each_state_renders_the_contracts_exact_sentence(): void
    {
        $presenter = app(AiUsagePresenter::class);
        $policy = $this->policy('growth');

        $this->assertSame(self::NORMAL, $presenter->sentenceFor(AiUsageState::Normal, $policy));
        $this->assertSame(self::NEARING, $presenter->sentenceFor(AiUsageState::NearingLimit, $policy));
        $this->assertSame(self::LIMIT, $presenter->sentenceFor(AiUsageState::LimitReached, $policy));

        // The date is the first day of the period AFTER the one described.
        $this->assertStringEndsWith('return on 1 January.', $presenter->sentenceFor(AiUsageState::LimitReached, $this->policy('growth', '2026-12')));
    }

    /**
     * T-UX-AI-2. The trial wording is keyed off the canonical policy and
     * nothing else; the resolver cannot return that policy until T-1, so this
     * proves the rendering, not a trial state.
     */
    public function test_the_trial_wording_belongs_to_the_trial_policy_alone(): void
    {
        $presenter = app(AiUsagePresenter::class);
        $trial = $this->policy('trial', 'trial:1');

        $this->assertSame(self::LIMIT_TRIAL, $presenter->sentenceFor(AiUsageState::LimitReached, $trial));
        $this->assertSame(self::TRIAL_LINE, $presenter->trialLineFor($trial));

        foreach (['core', 'growth', 'agency', 'unassigned'] as $key) {
            $this->assertNull($presenter->trialLineFor($this->policy($key)), "{$key} is not a trial.");
        }

        // Nothing infers a trial: no age, no plan flag, no missing payment method.
        $source = (string) file_get_contents(app_path('Library/Ai/AiUsagePresenter.php'));
        foreach (['trial_ends_at', 'created_at', 'is_complimentary', 'payment', 'Trialing'] as $proxy) {
            $this->assertStringNotContainsString($proxy, $source, "The presenter must not infer a trial from {$proxy}.");
        }
    }

    public function test_no_real_account_today_ever_shows_the_trial_line(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Trial probe ' . $tier->value, 'Trial account ' . $tier->value);
            $this->openWorkspacePeriod($workspace, $tier, 1_000_000, 1_000_000);
            $this->authenticateAs($customer);

            $html = $this->billingPage($workspace, $business)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::TRIAL_LINE, $html, "{$tier->value} is never on trial before T-1.");
            $this->assertStringNotContainsString(self::LIMIT_TRIAL, $html);
        }
    }

    // =================================================================
    // Core / Growth
    // =================================================================

    public function test_a_core_owner_sees_the_normal_state_and_no_business_rows(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $html = $this->billingPage($workspace, $business)->assertOk()->getContent();
        $section = $this->aiUsageSection($html);

        $this->assertNotNull($section, 'The AI usage section renders on Settings → Billing.');
        $this->assertStringContainsString(self::NORMAL, $this->text($section));
        $this->assertStringNotContainsString('ai-usage-business-row', $section, 'One allowance, so no per-Business list.');
    }

    public function test_a_growth_owner_nearing_the_limit_reads_the_nearing_sentence(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->addBusiness($customer, $workspace, 'Second Growth Business');
        $cap = (int) config('ai.budgets.growth.workspace_cap_microusd');
        $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Growth, $cap, intdiv($cap * 85, 100));
        $this->authenticateAs($customer);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $business)->assertOk()->getContent());

        $this->assertStringContainsString(self::NEARING, $this->text($section));
        $this->assertStringNotContainsString('ai-usage-business-row', $section, 'Growth with two Businesses still has one allowance.');
    }

    public function test_limit_reached_by_committed_usage_and_by_a_refusal_alone(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $cap = (int) config('ai.budgets.core.workspace_cap_microusd');
        $this->authenticateAs($customer);

        $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Core, $cap, $cap);
        $this->assertStringContainsString(self::LIMIT, $this->text((string) $this->aiUsageSection($this->billingPage($workspace, $business)->getContent())));

        // Back under the cap, but a budget refusal happened this period.
        DB::table('ai_usage_periods')->where('scope_id', $workspace->id)->update(['committed_microusd' => 10]);
        $this->recordRefusal($workspace, null, 'budget_exhausted', self::PERIOD, 'workspace');
        $this->assertStringContainsString(self::LIMIT, $this->text((string) $this->aiUsageSection($this->billingPage($workspace, $business)->getContent())));
    }

    public function test_other_refusals_and_last_periods_refusals_do_not_count(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $this->recordRefusal($workspace, null, 'interactive_share_exhausted', self::PERIOD, 'interactive_share');
        $this->recordRefusal($workspace, null, 'request_too_expensive', self::PERIOD);
        $this->recordRefusal($workspace, null, 'budget_exhausted', '2026-08', 'workspace');

        $this->assertStringContainsString(self::NORMAL, $this->text((string) $this->aiUsageSection($this->billingPage($workspace, $business)->getContent())));
    }

    // =================================================================
    // Agency
    // =================================================================

    public function test_an_agency_owner_sees_the_account_state_and_one_row_per_business(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $third = $this->addBusiness($this->createCustomer(), $workspace, 'Client Three');

        $businessCap = (int) config('ai.budgets.agency.business_cap_microusd');
        $this->openBusinessPeriod($workspace, $second, $businessCap, $businessCap); // at its own cap
        $this->openBusinessPeriod($workspace, $third, $businessCap, intdiv($businessCap * 9, 10)); // 90%
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->assertOk()->getContent());

        $this->assertStringContainsString(self::NORMAL, $this->text($section), 'The account headline is the Workspace allowance.');

        $rows = $this->rowStates($section);
        $this->assertSame([
            $first->uid => 'normal',
            $second->uid => 'limit_reached',
            $third->uid => 'nearing_limit',
        ], $rows);

        $text = $this->text($section);
        $this->assertStringContainsString('Client One', $text);
        $this->assertStringContainsString('Limit reached', $text);
        $this->assertStringContainsString('Nearing limit', $text);
    }

    public function test_a_business_refused_for_budget_this_period_shows_limit_reached_in_its_row(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Refusal Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $this->recordRefusal($workspace, $second, 'budget_exhausted', self::PERIOD, 'business'); // its own $6 cap
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());
        $rows = $this->rowStates($section);

        $this->assertSame('normal', $rows[$first->uid]);
        $this->assertSame('limit_reached', $rows[$second->uid]);

        // One client reaching its own allowance is not the agency's whole
        // allowance running out: the headline stays with the account.
        $this->assertStringContainsString(self::NORMAL, $this->text($section));
        $this->assertStringNotContainsString(self::LIMIT, $this->text($section));
    }

    public function test_an_agency_workspace_level_refusal_does_mean_the_account_limit_was_reached(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Workspace Refusal Agency');
        $this->recordRefusal($workspace, null, 'budget_exhausted', self::PERIOD, 'workspace'); // e.g. prospecting, Workspace cap only
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());

        $this->assertStringContainsString(self::LIMIT, $this->text($section));
        $this->assertSame('normal', $this->rowStates($section)[$first->uid], 'The Business itself was not refused.');
    }

    /**
     * The Workspace allowance refused a Business-scoped call while committed is
     * still just under the cap — the next request did not fit. The account is
     * used up; the client's own allowance is not, and its row says so.
     */
    public function test_a_business_scoped_call_refused_by_the_workspace_cap_makes_the_account_limit_reached(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Workspace Cap Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $workspaceCap = (int) config('ai.budgets.agency.workspace_cap_microusd');
        $businessCap = (int) config('ai.budgets.agency.business_cap_microusd');

        $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Agency, $workspaceCap, $workspaceCap - 1);
        $this->openBusinessPeriod($workspace, $second, $businessCap, intdiv($businessCap, 10));
        $this->recordRefusal($workspace, $second, 'budget_exhausted', self::PERIOD, 'workspace');
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());

        $this->assertStringContainsString(self::LIMIT, $this->text($section), 'Committed is under 100%, but the Workspace allowance refused a call.');
        $this->assertSame('normal', $this->rowStates($section)[$second->uid], 'Client Two\'s own allowance is at 10%, and its row stays truthful.');
    }

    public function test_a_call_both_caps_refused_is_shown_on_the_account_and_on_the_business(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Both Caps Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $this->recordRefusal($workspace, $second, 'budget_exhausted', self::PERIOD, 'workspace_and_business');
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());

        $this->assertStringContainsString(self::LIMIT, $this->text($section));
        $this->assertSame('limit_reached', $this->rowStates($section)[$second->uid]);
        $this->assertSame('normal', $this->rowStates($section)[$first->uid]);
    }

    public function test_an_interactive_share_refusal_is_neither_the_account_nor_the_business_limit(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Interactive Agency');
        $this->recordRefusal($workspace, $first, 'interactive_share_exhausted', self::PERIOD, 'interactive_share');
        $this->authenticateAs($owner);

        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());

        $this->assertStringContainsString(self::NORMAL, $this->text($section));
        $this->assertSame('normal', $this->rowStates($section)[$first->uid]);
    }

    /**
     * Refusals written before the scope column existed stay readable. Their
     * scope is taken as the Workspace's only where the Workspace cap was the
     * only cap that call could have met.
     */
    public function test_refusals_recorded_before_the_scope_existed_are_read_conservatively(): void
    {
        // Agency, Business-scoped, no scope: unknown — never forces the account headline.
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Legacy Client', 'Legacy Agency');
        $this->recordRefusal($workspace, $first, 'budget_exhausted', self::PERIOD);
        $this->authenticateAs($owner);
        $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent());
        $this->assertStringContainsString(self::NORMAL, $this->text($section));
        $this->assertSame('limit_reached', $this->rowStates($section)[$first->uid], 'It was this Business\'s call that was refused.');

        // Agency, no Business on the call: only the Workspace cap was checked.
        $this->recordRefusal($workspace, null, 'budget_exhausted', self::PERIOD);
        $this->assertStringContainsString(self::LIMIT, $this->text((string) $this->aiUsageSection($this->billingPage($workspace, $first)->getContent())));

        // One allowance: every budget refusal was that allowance.
        [$growthOwner, $growthBusiness, $growthWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Legacy Growth', 'Legacy Growth Account');
        $this->recordRefusal($growthWorkspace, $growthBusiness, 'budget_exhausted', self::PERIOD);
        $this->authenticateAs($growthOwner);
        $this->assertStringContainsString(self::LIMIT, $this->text((string) $this->aiUsageSection($this->billingPage($growthWorkspace, $growthBusiness)->getContent())));
    }

    public function test_with_one_allowance_a_business_scoped_refusal_is_the_account_limit(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->recordRefusal($workspace, $business, 'budget_exhausted', self::PERIOD, 'workspace');
        $this->authenticateAs($customer);

        $this->assertStringContainsString(self::LIMIT, $this->text((string) $this->aiUsageSection($this->billingPage($workspace, $business)->getContent())));
    }

    public function test_an_agency_wide_admin_sees_rows_but_a_scoped_admin_a_client_owner_and_staff_do_not(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Matrix Agency');
        $clientOwner = $this->createCustomer();
        $second = $this->addBusiness($clientOwner, $workspace, 'Client Two');

        $agencyAdmin = $this->createCustomer();
        $this->member($workspace, $agencyAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $scopedAdmin = $this->createCustomer();
        $this->assign($this->member($workspace, $scopedAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $second);

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->authenticateAs($agencyAdmin);
        $section = $this->aiUsageSection($this->billingPage($workspace, $second)->assertOk()->getContent());
        $this->assertNotNull($section);
        $this->assertCount(2, $this->rowStates((string) $section), 'An Agency-wide admin sees every Business.');

        $this->authenticateAs($scopedAdmin);
        $section = $this->aiUsageSection($this->billingPage($workspace, $second)->assertOk()->getContent());
        $this->assertNotNull($section, 'A scoped admin may manage this Business’s billing, so sees its state.');
        $this->assertSame([], $this->rowStates((string) $section), 'But not the other clients.');

        $this->authenticateAs($clientOwner);
        $section = $this->aiUsageSection($this->billingPage($workspace, $second)->assertOk()->getContent());
        $this->assertNotNull($section);
        $this->assertSame([], $this->rowStates((string) $section), 'A client never sees the other clients.');

        $this->authenticateAs($staff);
        $this->assertNull(
            $this->aiUsageSection($this->billingPage($workspace, $second)->assertOk()->getContent()),
            'Staff may open the page but are outside the billing authority, so see no AI usage.'
        );
    }

    // =================================================================
    // Tenancy
    // =================================================================

    public function test_a_foreign_account_or_business_is_not_found_and_leaks_no_usage(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Victim Client', 'Victim Agency');
        $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Agency, 1_000_000, 1_000_000);

        [$attacker, $ownBusiness, $ownWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Attacker Client', 'Attacker Agency');
        $this->authenticateAs($attacker);

        $this->billingPage($workspace, $business)->assertNotFound();
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$ownWorkspace->uid, $business->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $ownBusiness->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [(string) Str::uuid(), $business->uid]))->assertNotFound();

        // Their own page shows their own account, not the victim's used-up one.
        $section = (string) $this->aiUsageSection($this->billingPage($ownWorkspace, $ownBusiness)->assertOk()->getContent());
        $this->assertStringContainsString(self::NORMAL, $this->text($section));
        $this->assertStringNotContainsString('Victim Client', $section);
    }

    public function test_a_guest_cannot_reach_the_page(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->billingPage($workspace, $business)->assertUnauthorized();
    }

    public function test_an_account_with_no_active_plan_is_shown_no_ai_usage_at_all(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Unassigned']);
        $business = $this->addBusiness($customer, $workspace, 'Unassigned Business');
        $this->authenticateAs($customer);

        $html = $this->billingPage($workspace, $business)->assertOk()->getContent();

        $this->assertNull($this->aiUsageSection($html), 'No included AI means no allowance to describe.');
        $this->assertStringNotContainsString(self::LIMIT, html_entity_decode($html, ENT_QUOTES), 'Never "used up" for an allowance that never existed.');
    }

    // =================================================================
    // T-UX-AI-1 — what the customer must never see
    // =================================================================

    public function test_the_ai_usage_section_shows_no_figure_provider_model_or_meter_in_any_state(): void
    {
        [$owner, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Figures Agency');
        $second = $this->addBusiness($this->createCustomer(), $workspace, 'Client Two');
        $workspaceCap = (int) config('ai.budgets.agency.workspace_cap_microusd');
        $businessCap = (int) config('ai.budgets.agency.business_cap_microusd');
        $this->authenticateAs($owner);

        $forbidden = array_filter(array_unique([
            'token', 'usd', 'micro', 'price', 'provider', 'model', 'openai', 'gpt', 'percent', 'cost',
            strtolower((string) config('ai.routes.routine.model')),
            strtolower((string) config('ai.routes.reasoning.model')),
            strtolower((string) config('ai.routes.routine.provider')),
        ]));

        foreach ([0, intdiv($workspaceCap * 85, 100), $workspaceCap] as $committed) {
            DB::table('ai_usage_periods')->delete();
            $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Agency, $workspaceCap, $committed);
            $this->openBusinessPeriod($workspace, $second, $businessCap, $businessCap);

            $section = (string) $this->aiUsageSection($this->billingPage($workspace, $first)->assertOk()->getContent());
            $text = strtolower($this->text($section));

            $this->assertNotSame('', $text);

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $text, "The AI usage section must never show '{$word}'.");
            }

            $this->assertStringNotContainsString('$', $text, 'No dollar amount.');
            $this->assertStringNotContainsString('%', $text, 'No percentage.');
            $this->assertStringNotContainsString('progress', strtolower($section), 'No meter.');
            $this->assertStringNotContainsString('<meter', strtolower($section));

            // The only digits allowed are the day of the month in the reset date.
            $withoutDate = str_replace('1 october', '', $text);
            $this->assertDoesNotMatchRegularExpression('/\d/', $withoutDate, 'No number of any kind.');

            foreach ([(string) $committed, (string) $workspaceCap, (string) $businessCap, number_format($workspaceCap / 1_000_000, 2)] as $figure) {
                if ($figure !== '0') {
                    $this->assertStringNotContainsString($figure, $section);
                }
            }
        }
    }

    public function test_home_shows_no_ai_usage(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $cap = (int) config('ai.budgets.core.workspace_cap_microusd');
        $this->openWorkspacePeriod($workspace, WorkspacePlanTier::Core, $cap, $cap);
        $this->authenticateAs($customer);

        $html = (string) $this->home()->getContent();

        foreach ([self::NORMAL, self::NEARING, self::LIMIT, self::LIMIT_TRIAL, self::TRIAL_LINE, 'AI usage', 'usage-billing-ai-usage'] as $needle) {
            $this->assertStringNotContainsString($needle, html_entity_decode($html, ENT_QUOTES), "Home must not show '{$needle}'.");
        }

        foreach (['app/Library/Dashboard', 'resources/views/customer/dashboard'] as $dir) {
            foreach ((new \Symfony\Component\Finder\Finder())->files()->in(base_path($dir)) as $file) {
                $source = (string) file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('AiUsagePresenter', $source, $file->getRelativePathname() . ' must not read AI usage.');
                $this->assertStringNotContainsString('ai_usage', $source, $file->getRelativePathname() . ' must not read AI usage.');
            }
        }
    }

    // -----------------------------------------------------------------

    private function policy(string $key, string $periodKey = self::PERIOD): AiBudgetPolicy
    {
        return new AiBudgetPolicy($key, 1, $periodKey, 1_000_000, null, 3000);
    }

    private function billingPage(Workspace $workspace, Business $business): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]));
    }

    private function openWorkspacePeriod(Workspace $workspace, WorkspacePlanTier $tier, int $cap, int $committed): void
    {
        DB::table('ai_usage_periods')->insert($this->periodRow(AiUsagePeriod::SCOPE_WORKSPACE, (int) $workspace->id, $workspace, $tier->value, $cap, $committed));
    }

    private function openBusinessPeriod(Workspace $workspace, Business $business, int $cap, int $committed): void
    {
        DB::table('ai_usage_periods')->insert($this->periodRow(AiUsagePeriod::SCOPE_BUSINESS, (int) $business->id, $workspace, 'agency', $cap, $committed));
    }

    /** @return array<string, mixed> */
    private function periodRow(string $scope, int $scopeId, Workspace $workspace, string $policyKey, int $cap, int $committed): array
    {
        return [
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'workspace_id' => $workspace->id,
            'period_key' => self::PERIOD,
            'policy_key' => $policyKey,
            'policy_version' => 1,
            'cap_microusd' => $cap,
            'reserved_microusd' => 0,
            'committed_microusd' => $committed,
            'interactive_reserved_microusd' => 0,
            'interactive_committed_microusd' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    private function recordRefusal(Workspace $workspace, ?Business $business, string $reason, string $periodKey, ?string $scope = null): void
    {
        DB::table('ai_usage_ledger')->insert([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'business_id' => $business?->id,
            'category' => 'website_generation',
            'lane' => 'product',
            'model_route' => 'routine',
            'provider' => 'openai',
            'provider_model' => null,
            'price_version' => 1,
            'status' => 'refused',
            'refusal_reason' => $reason,
            'refusal_scope' => $scope,
            'estimated_cost_microusd' => 1000,
            'period_key' => $periodKey,
            'idempotency_key' => 'ai2-fixture:' . Str::uuid(),
            'created_at' => Carbon::now(),
            'settled_at' => Carbon::now(),
        ]);
    }

    /** The rendered AI usage card, or null when it is not on the page. */
    private function aiUsageSection(string $html): ?string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $node = (new DOMXPath($dom))->query('//*[@id="usage-billing-ai-usage"]')->item(0);

        return $node === null ? null : $dom->saveHTML($node);
    }

    private function text(string $fragment): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return array<string, string> business uid => state */
    private function rowStates(string $section): array
    {
        preg_match_all('/data-role="ai-usage-business-row" data-business-uid="([^"]+)" data-state="([^"]+)"/', $section, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $match) {
            $rows[$match[1]] = $match[2];
        }

        return $rows;
    }
}
