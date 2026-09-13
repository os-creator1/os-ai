<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 1B — the Advisor's presence on the
 * customer dashboard. No mutation, navigation, or polling behavior is
 * exercised here.
 *
 * Re-pointed by Customer Experience Slice 4, and again by Unified Business
 * Home C-2 (docs/automation/UNIFIED-BUSINESS-HOME-AND-COO-DECISION-ENGINE-
 * CONTRACT.md §2.4, §6.4, §6.5). Slice 4's "Recommended next steps" list of up
 * to five is gone; the Advisor now reaches Home as ONE candidate for "Your
 * next best move":
 *
 *  - it renders for the Business the CustomerContext resolved, so the
 *    fixture Business is made Active through BusinessRepository::updateStatus()
 *    — the resolver never selects a draft Business, and that is not weakened;
 *  - it reads the canonical RFC-002 work queue ONCE —
 *    OpportunityRepository::topForCustomer(selected Business, 20) — and the
 *    queue head is the candidate; "See all recommendations (N)" counts that
 *    same read ("20+" at the cap);
 *  - the actionable set is RFC-002's, so awaiting approval and in progress now
 *    count alongside open; snoozed, completed, dismissed and stale never do;
 *  - nothing actionable is "You're all caught up.", not an absent band, and
 *    the Advisor is never read at all while opportunity.enabled is false.
 *
 * The fixture Business raises no attention condition, so the queue head is
 * the move whenever one exists. Every exclusion test gives the excluded row a
 * HIGHER priority than an eligible control, so an exclusion cannot pass
 * merely because ordering put the control first. Rows are identified by their
 * own detail link: a registered type renders its registry title, never the
 * stored one. The escaping and no-leakage assertions are unchanged.
 */
class OpportunityDashboardHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    /** Registry title of the fixture's default type, missing_phone. */
    private const PHONE_TITLE = 'Add your business phone number';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_enabled_customer_with_business_sees_the_queue_head_as_the_next_best_move(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-band="next_best_move"', false);
        $response->assertSee('Your next best move');
        $response->assertDontSee('Recommended next steps');

        $band = $this->bandHtml($response);
        $this->assertStringContainsString('data-move-kind="opportunity"', $band);
        $this->assertStringContainsString(self::PHONE_TITLE, $band);
        $this->assertStringContainsString($this->showHref($opportunity), $band);
    }

    /**
     * The band reads the canonical queue once — topForCustomer() for the
     * selected Business, capped — observed on the real repository, never
     * replaced. However many rows exist, exactly one move renders, and only
     * the head is linked; the rest are counted, not listed.
     */
    public function test_one_capped_queue_read_renders_exactly_one_move_and_counts_the_rest(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $rows = [];

        for ($i = 0; $i < 8; $i++) {
            $rows[] = $this->createOpportunity($business, [
                'fingerprint' => hash('sha256', 'panel-limit-' . $i),
                'first_detected_at' => now()->addSeconds($i),
            ]);
        }

        $real = app(OpportunityRepository::class);
        $observed = Mockery::mock(OpportunityRepository::class);
        $observed->shouldReceive('topForCustomer')
            ->once()
            ->with(Mockery::on(fn ($candidate) => $candidate instanceof Business && (int) $candidate->id === (int) $business->id), BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP)
            ->andReturnUsing(fn (Business $selected, int $limit) => $real->topForCustomer($selected, $limit));
        $observed->shouldNotReceive('paginateForCustomer');
        $this->app->instance(OpportunityRepository::class, $observed);

        $response = $this->get(route('user.home'));
        $response->assertOk();
        $band = $this->bandHtml($response);

        $this->assertSame(1, substr_count($band, 'data-role="next-best-move"'), 'Exactly one move renders.');
        $this->assertSame(1, substr_count($band, 'data-role="next-best-move-action"'));
        $this->assertStringContainsString($this->showHref($rows[0]), $band, 'Equal priority: the earliest detection heads the queue.');

        foreach (array_slice($rows, 1) as $row) {
            $response->assertDontSee($this->showHref($row), false);
        }

        $this->assertStringContainsString('See all recommendations (8)', $band);
    }

    /**
     * The move is exactly the head topForCustomer() returns for the selected
     * Business (priority, then impact, urgency, first detection). Completing
     * each head in turn walks the rendered move through that whole order.
     */
    public function test_the_move_follows_the_canonical_queue_order(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $low = $this->createOpportunity($business, ['priority_score' => 10]);
        $high = $this->createOpportunity($business, ['priority_score' => 90]);
        $middleHighImpact = $this->createOpportunity($business, ['priority_score' => 50, 'impact' => 5]);
        $middleLowImpact = $this->createOpportunity($business, ['priority_score' => 50, 'impact' => 1]);

        $expected = app(OpportunityRepository::class)
            ->topForCustomer($business, BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP)
            ->pluck('id')
            ->all();

        $this->assertSame([$high->id, $middleHighImpact->id, $middleLowImpact->id, $low->id], $expected);

        foreach ([$high, $middleHighImpact, $middleLowImpact, $low] as $remaining => $head) {
            $response = $this->get(route('user.home'));
            $response->assertOk();
            $band = $this->bandHtml($response);

            $this->assertStringContainsString($this->showHref($head), $band);
            $this->assertStringContainsString('See all recommendations (' . (4 - $remaining) . ')', $band);

            $head->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }

        $this->assertStringContainsString("You're all caught up.", $this->bandHtml($this->get(route('user.home'))));
    }

    public function test_stale_opportunities_are_never_the_move(): void
    {
        $this->assertOnlyTheControlIsActionable(['freshness' => 'stale']);
    }

    public function test_snoozed_opportunities_are_never_the_move(): void
    {
        $this->assertOnlyTheControlIsActionable(['status' => 'snoozed', 'snoozed_until' => now()->addDay()]);
    }

    public function test_completed_opportunities_are_never_the_move(): void
    {
        $this->assertOnlyTheControlIsActionable(['status' => 'completed', 'completed_at' => now()]);
    }

    public function test_dismissed_opportunities_are_never_the_move(): void
    {
        $this->assertOnlyTheControlIsActionable(['status' => 'dismissed', 'dismissed_at' => now()]);
    }

    /**
     * RFC-002's actionable set includes work the customer has already begun:
     * a recommendation awaiting their approval is still their next move.
     */
    public function test_awaiting_approval_opportunities_are_actionable(): void
    {
        $this->assertTheHigherPriorityRowIsTheMove(['status' => 'awaiting_approval']);
    }

    public function test_in_progress_opportunities_are_actionable(): void
    {
        $this->assertTheHigherPriorityRowIsTheMove(['status' => 'in_progress']);
    }

    public function test_open_opportunities_are_actionable(): void
    {
        $this->assertTheHigherPriorityRowIsTheMove(['status' => 'open']);
    }

    public function test_another_tenants_opportunities_never_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $control = $this->createOpportunity($business, ['priority_score' => 10]);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $stranger = $this->createOpportunity($strangerBusiness, ['priority_score' => 99, 'type' => 'fixture_unregistered_type', 'title' => 'Stranger Panel Item']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertStringContainsString($this->showHref($control), $band);
        $this->assertStringContainsString('See all recommendations (1)', $band);
        $response->assertDontSee('Stranger Panel Item');
        $response->assertDontSee($this->showHref($stranger), false);
    }

    public function test_the_move_links_to_the_owned_detail_route(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="' . preg_quote(route('customer.opportunities.show', $opportunity->id), '#') . '"[^>]*data-role="next-best-move-action"|data-role="next-best-move-action"[^>]*href="' . preg_quote(route('customer.opportunities.show', $opportunity->id), '#') . '"#',
            $band,
        );
        $this->assertStringContainsString('Open recommendation', $band);
    }

    public function test_the_band_links_to_the_opportunities_queue(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertStringContainsString('href="' . route('customer.opportunities.index') . '"', $band);
        $this->assertStringContainsString('See all recommendations (1)', $band);
    }

    /** §2.4 — nothing actionable: the band says so, never the removed neutral card. */
    public function test_no_actionable_opportunities_is_all_caught_up(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $stale = $this->createOpportunity($business, ['freshness' => 'stale']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $band = $this->bandHtml($response);
        $this->assertStringContainsString("You're all caught up.", $band);
        $this->assertStringNotContainsString('data-role="next-best-move"', $band);
        $this->assertStringNotContainsString('data-role="next-best-move-all"', $band);
        $response->assertDontSee('No opportunities are available right now.');
        $response->assertDontSee($this->showHref($stale), false);
        // The navigation still offers the Advisor queue; the band adds no link to it.
        $this->assertStringNotContainsString(route('customer.opportunities.index'), $band);
    }

    public function test_disabled_flag_leaves_the_advisor_out_of_the_move_while_dashboard_remains_200(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, ['type' => 'fixture_unregistered_type', 'title' => 'Should Be Hidden Panel Item']);
        config()->set('opportunity.enabled', false);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $this->assertStringContainsString("You're all caught up.", $this->bandHtml($response));
        $response->assertDontSee('Should Be Hidden Panel Item');
        $response->assertDontSee(route('customer.opportunities.index'), false);
        $response->assertDontSee(route('customer.opportunities.show', $opportunity->id), false);
    }

    /**
     * Container-bound Mockery contract mock — the only technique that proves
     * the Advisor read was never reached, rather than inferring it from absent
     * titles (which a query that ran but matched nothing would also satisfy).
     * It targets the move's read, topForCustomer(); the strict mock also fails
     * on any other repository call. The band is asserted to have rendered and
     * NOT degraded — so a swallowed mock exception cannot pass this — and the
     * Business Home to have rendered, so the flag, not a missing Business, is
     * what kept the Advisor unread.
     */
    public function test_disabled_flag_never_reads_the_advisor_repository(): void
    {
        $this->actingAsCustomerWithBusiness();
        config()->set('opportunity.enabled', false);

        $opportunityRepository = Mockery::mock(OpportunityRepository::class);
        $opportunityRepository->shouldNotReceive('topForCustomer');
        $opportunityRepository->shouldNotReceive('paginateForCustomer');
        $this->app->instance(OpportunityRepository::class, $opportunityRepository);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $band = $this->bandHtml($response);
        $this->assertStringNotContainsString('data-band-state="failed"', $band);
        $this->assertStringContainsString("You're all caught up.", $band);
    }

    public function test_customer_without_a_business_receives_dashboard_200_with_the_band_absent(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('data-band="next_best_move"', false);
        $response->assertDontSee(route('customer.opportunities.index'), false);
    }

    /**
     * A type the registry does not know falls back to its stored title, which
     * must still be escaped.
     */
    public function test_opportunity_titles_are_escaped(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['type' => 'fixture_unregistered_type', 'title' => '<script>alert(1)</script>']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->bandHtml($response));
    }

    public function test_action_keys_hashes_and_execution_ids_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $recommendedAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ];
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($recommendedAction),
            'action_schema_version' => 1,
        ]);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'dashboard-panel-execution'),
            'status' => OpportunityActionExecutionStatus::Running->value,
        ]);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertStringContainsString($this->showHref($opportunity), $band, 'Precondition: the recommendation is the move.');
        $this->assertStringContainsString(self::PHONE_TITLE, $band);
        $response->assertDontSee($opportunity->recommended_action_hash);
        $response->assertDontSee('add_phone');
        $response->assertDontSee('+15551234567');
        $response->assertDontSee(hash('sha256', 'dashboard-panel-execution'));
    }

    /**
     * The next best move sits inside the rebuilt Business Home: the Business
     * frame, its one <main> and one <h1> naming the Business, and none of the
     * removed user-scoped tiles (the B5-retired `#sms-reports` pie, the
     * invoice figure, the legacy quick-send link) — nor Slice 4's list bands.
     */
    public function test_the_band_renders_inside_the_rebuilt_business_home(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $response->assertSee('data-band="next_best_move"', false);
        $response->assertDontSee('data-band="recommendations"', false);
        $response->assertDontSee('data-band="attention"', false);
        $this->assertSame(1, substr_count($html, '<main'));
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*' . preg_quote((string) $business->name, '#') . '.*</h1>#s', $html);
        $response->assertDontSee('id="sms-reports"', false);
        $response->assertDontSee(route('customer.sms.quick_send'), false);
        $this->assertStringNotContainsString('<sup>', $this->mainHtml($html), 'No legacy "unpaid / total" invoice figure.');
    }

    /**
     * One eligible control and one excluded row of HIGHER priority: were the
     * excluded row in the queue it would be the move, so the control being
     * the move — and the only one counted — proves the exclusion.
     *
     * @param  array<string, mixed>  $excluded
     */
    private function assertOnlyTheControlIsActionable(array $excluded): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $control = $this->createOpportunity($business, ['priority_score' => 10]);
        $row = $this->createOpportunity($business, array_merge(['priority_score' => 90], $excluded));

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertStringContainsString($this->showHref($control), $band);
        $this->assertStringContainsString('See all recommendations (1)', $band);
        $response->assertDontSee($this->showHref($row), false);
    }

    /**
     * An actionable row of higher priority than an open control is the move,
     * and both are counted.
     *
     * @param  array<string, mixed>  $actionable
     */
    private function assertTheHigherPriorityRowIsTheMove(array $actionable): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $control = $this->createOpportunity($business, ['priority_score' => 10]);
        $row = $this->createOpportunity($business, array_merge(['priority_score' => 90], $actionable));

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $band = $this->bandHtml($response);
        $this->assertStringContainsString($this->showHref($row), $band);
        $this->assertStringContainsString('See all recommendations (2)', $band);
        $response->assertDontSee($this->showHref($control), false);
    }

    private function showHref(Opportunity $opportunity): string
    {
        return 'href="' . route('customer.opportunities.show', $opportunity->id) . '"';
    }

    /** The next best move band's own markup, or a failure when it is absent. */
    private function bandHtml(TestResponse $response): string
    {
        $html = $response->getContent();
        $start = strpos($html, 'data-band="next_best_move"');
        $this->assertNotFalse($start, 'The next best move band must render.');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function mainHtml(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        $this->ensureRequiredAppConfigRowsExist();

        // The CustomerContext resolver selects only an active Business; the
        // repository's own lifecycle method makes the fixture one.
        $business = app(BusinessRepository::class)->updateStatus($this->createBusinessForOpportunities(), BusinessStatus::Active);
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
    }

    private function actingAsCustomerWithoutBusiness(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    /**
     * Seeds only the app_config rows the customer HTTP render path actually
     * reads (mirrors BusinessOnboardingHttpTest's own identical helper).
     */
    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
