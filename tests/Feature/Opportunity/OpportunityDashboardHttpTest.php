<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
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
 * Re-pointed by Customer Experience Slice 4 (docs/automation/CUSTOMER-
 * EXPERIENCE-REDESIGN-SLICE-4-DASHBOARD.md §3, §6, §18 #16–#18). The
 * sole-Business top-5 panel is gone; the Business Home's "Recommended next
 * steps" band replaced it:
 *
 *  - it renders for the Business the CustomerContext resolved, so the
 *    fixture Business is made Active through BusinessRepository::updateStatus()
 *    — the resolver never selects a draft Business, and that is not weakened;
 *  - it reads OpportunityRepository::paginateForCustomer(selected Business,
 *    status = open, freshness = current) and shows the first five;
 *  - awaiting approval, in progress, snoozed, completed, dismissed and stale
 *    recommendations never appear;
 *  - it is ABSENT, not an empty card, when nothing is eligible, and never read
 *    at all while opportunity.enabled is false.
 *
 * Every exclusion test also renders an eligible control recommendation, so an
 * absent band can never make an exclusion pass vacuously. The escaping and
 * no-leakage assertions are unchanged.
 */
class OpportunityDashboardHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const CONTROL_TITLE = 'Eligible Control Recommendation';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_enabled_customer_with_business_sees_recommended_next_steps(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Add your business phone number']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-band="recommendations"', false);
        $response->assertSee('Recommended next steps');
        $this->assertStringContainsString('Add your business phone number', $this->bandHtml($response));
    }

    /**
     * The band reads the CURRENT repository path — paginateForCustomer() for
     * the selected Business, open and current only — and renders no more
     * than five of its rows. The call is observed on the real repository,
     * never replaced.
     */
    public function test_at_most_five_recommendations_render_from_the_current_repository_read(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        for ($i = 0; $i < 8; $i++) {
            $this->createOpportunity($business, [
                'title' => 'Panel Row ' . $i,
                'fingerprint' => hash('sha256', 'panel-limit-' . $i),
                'first_detected_at' => now()->addSeconds($i),
            ]);
        }

        $real = app(OpportunityRepository::class);
        $observed = Mockery::mock(OpportunityRepository::class);
        $observed->shouldReceive('paginateForCustomer')
            ->once()
            ->with(Mockery::on(fn ($candidate) => $candidate instanceof Business && (int) $candidate->id === (int) $business->id), ['status' => 'open', 'freshness' => 'current'])
            ->andReturnUsing(fn (Business $selected, array $filters) => $real->paginateForCustomer($selected, $filters));
        $this->app->instance(OpportunityRepository::class, $observed);

        $response = $this->get(route('user.home'));
        $response->assertOk();
        $band = $this->bandHtml($response);

        $this->assertSame(5, substr_count($band, 'data-role="recommendation"'), 'No more than five recommendations render.');

        foreach (range(0, 4) as $i) {
            $this->assertStringContainsString('Panel Row ' . $i, $band);
        }

        foreach (range(5, 7) as $i) {
            $response->assertDontSee('Panel Row ' . $i);
        }
    }

    /**
     * The rendered order is exactly the order paginateForCustomer() returns
     * for the selected Business (priority, then impact, urgency, first
     * detection) — never the deleted topForCustomer() panel order.
     */
    public function test_ordering_matches_the_current_paginate_for_customer_order(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Low Priority Panel Item', 'priority_score' => 10]);
        $this->createOpportunity($business, ['title' => 'High Priority Panel Item', 'priority_score' => 90]);
        $this->createOpportunity($business, ['title' => 'Middle Priority High Impact Item', 'priority_score' => 50, 'impact' => 5]);
        $this->createOpportunity($business, ['title' => 'Middle Priority Low Impact Item', 'priority_score' => 50, 'impact' => 1]);

        $expected = collect(app(OpportunityRepository::class)->paginateForCustomer($business, ['status' => 'open', 'freshness' => 'current'])->items())
            ->take(5)
            ->pluck('title')
            ->all();

        $this->assertSame(['High Priority Panel Item', 'Middle Priority High Impact Item', 'Middle Priority Low Impact Item', 'Low Priority Panel Item'], $expected);

        $response = $this->get(route('user.home'));
        $response->assertOk();
        $band = $this->bandHtml($response);

        $positions = array_map(fn (string $title) => strpos($band, $title), $expected);

        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Rendered in paginateForCustomer() order.');
    }

    public function test_stale_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'Stale Panel Item', 'freshness' => 'stale'], 'Stale Panel Item');
    }

    public function test_snoozed_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'Snoozed Panel Item', 'status' => 'snoozed'], 'Snoozed Panel Item');
    }

    public function test_completed_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'Completed Panel Item', 'status' => 'completed'], 'Completed Panel Item');
    }

    public function test_dismissed_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'Dismissed Panel Item', 'status' => 'dismissed'], 'Dismissed Panel Item');
    }

    public function test_awaiting_approval_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'Awaiting Approval Panel Item', 'status' => 'awaiting_approval'], 'Awaiting Approval Panel Item');
    }

    public function test_in_progress_opportunities_do_not_appear(): void
    {
        $this->assertOnlyTheControlAppears(['title' => 'In Progress Panel Item', 'status' => 'in_progress'], 'In Progress Panel Item');
    }

    public function test_open_opportunities_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Open Panel Item', 'status' => 'open']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $this->assertStringContainsString('Open Panel Item', $this->bandHtml($response));
    }

    public function test_another_tenants_opportunities_never_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => self::CONTROL_TITLE]);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunity($strangerBusiness, ['title' => 'Stranger Panel Item']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $this->assertStringContainsString(self::CONTROL_TITLE, $this->bandHtml($response));
        $response->assertDontSee('Stranger Panel Item');
    }

    public function test_each_row_links_to_the_owned_detail_route(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, ['title' => 'Linked Panel Item']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $this->assertStringContainsString('href="' . route('customer.opportunities.show', $opportunity->id) . '"', $this->bandHtml($response));
    }

    public function test_the_band_links_to_the_opportunities_queue(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $this->assertStringContainsString('href="' . route('customer.opportunities.index') . '"', $this->bandHtml($response));
    }

    /** §6 / §18 #18 — nothing eligible: the band is absent, not the removed neutral card. */
    public function test_no_eligible_opportunities_leaves_the_band_absent(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Only A Stale One', 'freshness' => 'stale']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $response->assertDontSee('data-band="recommendations"', false);
        $response->assertDontSee('Recommended next steps');
        $response->assertDontSee('No opportunities are available right now.');
        $response->assertDontSee('Only A Stale One');
    }

    public function test_disabled_flag_hides_the_band_while_dashboard_remains_200(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, ['title' => 'Should Be Hidden Panel Item']);
        config()->set('opportunity.enabled', false);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $response->assertDontSee('data-band="recommendations"', false);
        $response->assertDontSee('Should Be Hidden Panel Item');
        $response->assertDontSee(route('customer.opportunities.index'), false);
        $response->assertDontSee(route('customer.opportunities.show', $opportunity->id), false);
    }

    /**
     * Container-bound Mockery contract mock — the only technique that proves
     * the Advisor read was never reached, rather than inferring it from absent
     * titles (which a query that ran but matched nothing would also satisfy).
     * It targets the Dashboard's current read, paginateForCustomer(); the
     * strict mock also fails on any other repository call. The Business Home
     * is asserted to have rendered, so the flag — not a missing Business — is
     * what kept the Advisor unread.
     */
    public function test_disabled_flag_never_reads_the_advisor_repository(): void
    {
        $this->actingAsCustomerWithBusiness();
        config()->set('opportunity.enabled', false);

        $opportunityRepository = Mockery::mock(OpportunityRepository::class);
        $opportunityRepository->shouldNotReceive('paginateForCustomer');
        $this->app->instance(OpportunityRepository::class, $opportunityRepository);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
    }

    public function test_customer_without_a_business_receives_dashboard_200_with_panel_absent(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('data-band="recommendations"', false);
        $response->assertDontSee(route('customer.opportunities.index'), false);
    }

    public function test_opportunity_titles_are_escaped(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => '<script>alert(1)</script>']);

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
        $this->assertStringContainsString((string) $opportunity->title, $this->bandHtml($response), 'Precondition: the recommendation rendered.');
        $response->assertDontSee($opportunity->recommended_action_hash);
        $response->assertDontSee('add_phone');
        $response->assertDontSee(hash('sha256', 'dashboard-panel-execution'));
    }

    /**
     * The recommendations band sits inside the rebuilt Business Home: the
     * Business frame, its one <main> and one <h1> naming the Business, and
     * none of the removed user-scoped tiles (the B5-retired `#sms-reports`
     * pie, the invoice figure, the legacy quick-send link).
     */
    public function test_the_band_renders_inside_the_rebuilt_business_home(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('data-kind="business"', false);
        $response->assertSee('data-band="recommendations"', false);
        $this->assertSame(1, substr_count($html, '<main'));
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*' . preg_quote((string) $business->name, '#') . '.*</h1>#s', $html);
        $response->assertDontSee('id="sms-reports"', false);
        $response->assertDontSee(route('customer.sms.quick_send'), false);
        $this->assertStringNotContainsString('<sup>', $this->mainHtml($html), 'No legacy "unpaid / total" invoice figure.');
    }

    /**
     * One eligible control recommendation and one excluded recommendation:
     * the control proves the band rendered, so the exclusion cannot pass
     * because the band was missing.
     *
     * @param  array<string, mixed>  $excluded
     */
    private function assertOnlyTheControlAppears(array $excluded, string $excludedTitle): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => self::CONTROL_TITLE]);
        $this->createOpportunity($business, $excluded);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $this->assertStringContainsString(self::CONTROL_TITLE, $this->bandHtml($response));
        $response->assertDontSee($excludedTitle);
    }

    /** The Recommended next steps band's own markup, or a failure when it is absent. */
    private function bandHtml(TestResponse $response): string
    {
        $html = $response->getContent();
        $start = strpos($html, 'data-band="recommendations"');
        $this->assertNotFalse($start, 'The Recommended next steps band must render.');
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
