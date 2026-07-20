<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Helpers\Helper;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 1 — read-only queue/detail pages
 * only. No mutation route exists yet, so none is exercised here.
 */
class OpportunityQueueHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setOpportunityEngineEnabled(true);
    }

    public function test_authenticated_customer_with_primary_business_sees_index(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $this->get(route('customer.opportunities.index'))->assertOk();
    }

    public function test_authenticated_customer_sees_owned_detail(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business);

        $this->get(route('customer.opportunities.show', $opportunity->id))->assertOk();
    }

    public function test_guest_index_returns_401(): void
    {
        $this->get(route('customer.opportunities.index'))->assertUnauthorized();
    }

    public function test_guest_detail_returns_401(): void
    {
        $this->get(route('customer.opportunities.show', 1))->assertUnauthorized();
    }

    public function test_customer_with_no_primary_business_redirects_to_onboarding(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $this->get(route('customer.opportunities.index'))
            ->assertRedirect(route('customer.onboarding.show'));

        $this->get(route('customer.opportunities.show', 1))
            ->assertRedirect(route('customer.onboarding.show'));
    }

    public function test_another_tenants_opportunity_is_absent_from_the_list(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'My Own Opportunity']);

        $strangerBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunity($strangerBusiness, ['title' => 'Stranger Opportunity']);

        $response = $this->get(route('customer.opportunities.index'));

        $response->assertOk();
        $response->assertSee('My Own Opportunity');
        $response->assertDontSee('Stranger Opportunity');
    }

    public function test_another_tenants_detail_url_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();

        $strangerBusiness = $this->createBusinessForOpportunities();
        $strangerOpportunity = $this->createOpportunity($strangerBusiness);

        $this->get(route('customer.opportunities.show', $strangerOpportunity->id))->assertNotFound();
    }

    public function test_default_list_includes_current_and_excludes_stale(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Current One', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale One', 'freshness' => 'stale']);

        $response = $this->get(route('customer.opportunities.index'));

        $response->assertSee('Current One');
        $response->assertDontSee('Stale One');
    }

    public function test_freshness_current_shows_current_only(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Current Two', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Two', 'freshness' => 'stale']);

        $response = $this->get(route('customer.opportunities.index', ['freshness' => 'current']));

        $response->assertSee('Current Two');
        $response->assertDontSee('Stale Two');
    }

    public function test_freshness_stale_shows_stale_only(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Current Three', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Three', 'freshness' => 'stale']);

        $response = $this->get(route('customer.opportunities.index', ['freshness' => 'stale']));

        $response->assertDontSee('Current Three');
        $response->assertSee('Stale Three');
    }

    public function test_freshness_all_shows_both(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Current Four', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Four', 'freshness' => 'stale']);

        $response = $this->get(route('customer.opportunities.index', ['freshness' => 'all']));

        $response->assertSee('Current Four');
        $response->assertSee('Stale Four');
    }

    public function test_invalid_freshness_normalizes_to_current(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Current Five', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Five', 'freshness' => 'stale']);

        $response = $this->get(route('customer.opportunities.index', ['freshness' => 'not-a-real-value']));

        $response->assertOk();
        $response->assertSee('Current Five');
        $response->assertDontSee('Stale Five');
    }

    public function test_each_valid_status_value_can_be_filtered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (OpportunityStatus::cases() as $status) {
            $this->createOpportunity($business, [
                'title' => 'Status Fixture ' . $status->value,
                'status' => $status->value,
                'freshness' => 'current',
            ]);
        }

        foreach (OpportunityStatus::cases() as $status) {
            $response = $this->get(route('customer.opportunities.index', ['status' => $status->value, 'freshness' => 'all']));

            $response->assertOk();
            $response->assertSee('Status Fixture ' . $status->value);

            foreach (OpportunityStatus::cases() as $other) {
                if ($other === $status) {
                    continue;
                }

                $response->assertDontSee('Status Fixture ' . $other->value);
            }
        }
    }

    public function test_invalid_status_is_ignored_rather_than_passed_through(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Open Fixture', 'status' => OpportunityStatus::Open->value]);

        $response = $this->get(route('customer.opportunities.index', ['status' => 'not-a-real-status']));

        $response->assertOk();
        $response->assertSee('Open Fixture');
    }

    public function test_repository_ordering_is_preserved_in_rendered_row_order(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Low Priority', 'priority_score' => 10]);
        $this->createOpportunity($business, ['title' => 'High Priority', 'priority_score' => 90]);

        $response = $this->get(route('customer.opportunities.index'));
        $body = $response->getContent();

        $highPosition = strpos($body, 'High Priority');
        $lowPosition = strpos($body, 'Low Priority');

        $this->assertNotFalse($highPosition);
        $this->assertNotFalse($lowPosition);
        $this->assertLessThan($lowPosition, $highPosition);
    }

    public function test_empty_state_renders(): void
    {
        $this->actingAsCustomerWithBusiness();

        $response = $this->get(route('customer.opportunities.index'));

        $response->assertOk();
        $response->assertSee('No opportunities are available right now.');
    }

    public function test_pagination_links_preserve_normalized_status_and_freshness(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        for ($i = 0; $i < 101; $i++) {
            $this->createOpportunity($business, [
                'title' => 'Bulk Opportunity ' . $i,
                'status' => OpportunityStatus::Open->value,
                'freshness' => 'current',
                'fingerprint' => hash('sha256', 'bulk-' . $i),
            ]);
        }

        $response = $this->get(route('customer.opportunities.index', ['status' => 'open', 'freshness' => 'current']));
        $body = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('status=open', $body);
        $this->assertStringContainsString('freshness=current', $body);
    }

    public function test_disabled_index_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();
        $this->setOpportunityEngineEnabled(false);

        $this->get(route('customer.opportunities.index'))->assertNotFound();
    }

    public function test_disabled_detail_returns_404(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business);
        $this->setOpportunityEngineEnabled(false);

        $this->get(route('customer.opportunities.show', $opportunity->id))->assertNotFound();
    }

    public function test_disabled_navigation_entry_is_absent(): void
    {
        $this->actingAsCustomerWithBusiness();
        $this->setOpportunityEngineEnabled(false);

        // Uses the existing, already-proven customer.business.edit page
        // (same layout/sidebar-rendering path) rather than the legacy
        // user.home dashboard, which carries unrelated onboarding-gate
        // middleware and heavy analytics queries out of scope here.
        $response = $this->get(route('customer.business.edit'));

        $response->assertOk();
        $response->assertDontSee(route('customer.opportunities.index'), false);
    }

    public function test_enabled_navigation_entry_is_present(): void
    {
        $this->actingAsCustomerWithBusiness();
        $this->setOpportunityEngineEnabled(true);

        $response = $this->get(route('customer.business.edit'));

        $response->assertOk();
        $response->assertSee(route('customer.opportunities.index'), false);
    }

    public function test_detail_displays_the_latest_execution_by_attempt_number_then_id(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());
        $user = $this->createUser();

        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-http-1'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'safe_error_summary' => 'Opportunity action could not be completed.',
        ]);
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-http-2'),
            'attempt_number' => 2,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'safe_result_summary' => 'Business phone updated and verified.',
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('Business phone updated and verified.');
        $response->assertDontSee('Opportunity action could not be completed.');
    }

    public function test_detail_safely_handles_no_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('No execution has been started for this opportunity yet.');
    }

    public function test_title_summary_and_evidence_are_escaped(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'title' => '<script>alert(1)</script>',
            'summary' => '<b>bold</b> summary',
            'evidence' => [
                ['summary' => '<img src=x onerror=alert(2)>', 'retrieved_at' => now()->toIso8601String()],
            ],
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<b>bold</b>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_only_evidence_summary_and_retrieved_at_are_displayed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'evidence' => [[
                'summary' => 'The business phone number is not set.',
                'retrieved_at' => '2026-01-01T00:00:00+00:00',
                'fact_key' => 'phone_blank',
                'observed_value' => 'super-secret-observed-value',
                'source_identifier' => 'business:42:phone',
                'content_hash' => hash('sha256', 'evidence-content'),
            ]],
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('The business phone number is not set.');
        $response->assertSee('2026-01-01T00:00:00+00:00');
        $response->assertDontSee('phone_blank');
        $response->assertDontSee('super-secret-observed-value');
        $response->assertDontSee('business:42:phone');
        $response->assertDontSee(hash('sha256', 'evidence-content'));
    }

    public function test_recommended_action_hash_is_not_rendered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee($opportunity->recommended_action_hash);
    }

    public function test_raw_action_key_is_not_rendered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('add_phone');
        $response->assertSee('Add phone number');
    }

    public function test_raw_handler_and_verifier_identifiers_are_not_rendered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('business.update_phone');
        $response->assertDontSee('business.phone_matches_parameter');
    }

    public function test_idempotency_key_is_not_rendered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());
        $user = $this->createUser();
        $key = hash('sha256', 'do-not-leak-this-idempotency-key');
        $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee($key);
    }

    public function test_fixed_completion_policy_label_is_rendered(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('System verified');
        $response->assertDontSee('system_verified');
    }

    public function test_pending_or_running_execution_has_aria_live_polite(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributes());
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'aria-live-test'),
            'status' => OpportunityActionExecutionStatus::Running->value,
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('aria-live="polite"', false);
    }

    public function test_malformed_evidence_does_not_break_the_page_or_leak_json(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'evidence' => [
                ['no_summary_key' => true],
                'not-an-array-item',
                ['summary' => ''],
                ['summary' => null],
            ],
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('No supporting evidence is available.');
        $response->assertDontSee('no_summary_key');
        $response->assertDontSee('not-an-array-item');
    }

    public function test_unknown_action_key_uses_recommended_action_label(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $recommendedAction = [
            'schema_version' => 1,
            'action_key' => 'some_future_action',
            'parameters' => ['value' => 'x'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($recommendedAction),
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('Recommended action');
        $response->assertDontSee('some_future_action');
    }

    public function test_unknown_completion_policy_uses_neutral_label(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => [
                'schema_version' => 1,
                'action_key' => 'add_phone',
                'parameters' => ['value' => '+15551234567'],
                'approval_required' => true,
                'completion_policy' => 'not_a_real_policy',
            ],
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('Completion status unavailable');
        $response->assertDontSee('not_a_real_policy');
    }

    /**
     * @return array<string, mixed>
     */
    private function addPhoneAttributes(): array
    {
        $recommendedAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ];

        return [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($recommendedAction),
            'action_schema_version' => 1,
        ];
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        $this->ensureRequiredAppConfigRowsExist();

        $business = $this->createBusinessForOpportunities();
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
     * MenuServiceProvider::boot() builds and View::share()s 'menuData' once,
     * at application boot — before this test ever changes
     * config('opportunity.enabled'). Changing the config alone leaves the
     * already-shared menu stale, so the nav-entry assertions would see
     * whatever value was true at boot time regardless of what a test sets
     * later. This re-runs the exact same source (Helper::menuData()) and
     * the same array-of-two-decoded-objects shape MenuServiceProvider
     * shares, so `$menuData[0]->customer`/`->admin` in
     * resources/views/panels/sidebar.blade.php keeps working, now
     * reflecting the flag value this test actually wants in effect.
     */
    private function setOpportunityEngineEnabled(bool $enabled): void
    {
        config()->set('opportunity.enabled', $enabled);

        $menuData = json_decode(json_encode(Helper::menuData()));

        View::share('menuData', [$menuData, $menuData]);
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
