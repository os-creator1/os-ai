<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 5 Slices 1–2 — admin authorization scaffold, read-only
 * Opportunity index, and read-only Opportunity detail with transition and
 * execution history. No run/candidate inspection and no admin mutation
 * exists yet.
 */
class AdminOpportunityControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        config()->set('opportunity.enabled', true);
    }

    public function test_guest_cannot_access_the_index(): void
    {
        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_the_index(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    /**
     * Direct regression guard, mirroring AdminBusinessControllerTest's own
     * identical case: a non-admin account (users.is_admin = false) must be
     * blocked even when its session happens to carry the exact permission
     * strings the feature-level check looks for.
     */
    public function test_non_admin_is_blocked_even_with_matching_permission_strings_in_session(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);
        $this->withSession(['permissions' => collect(['access backend', 'view opportunities'])]);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_without_backend_access_is_blocked(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_view_opportunities_permission_is_blocked(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_with_view_opportunities_can_access_the_index(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Add your business phone number']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.index'))
            ->assertOk()
            ->assertSee('Add your business phone number');
    }

    public function test_feature_disabled_returns_404_for_an_otherwise_authorized_admin(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);
        config()->set('opportunity.enabled', false);

        $this->get(route('admin.opportunities.index'))->assertNotFound();
    }

    public function test_authorized_admin_sees_opportunities_belonging_to_different_customers(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Business A Opportunity']);
        $this->createOpportunity($businessB, ['title' => 'Business B Opportunity']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee('Business A Opportunity');
        $response->assertSee('Business B Opportunity');
    }

    /**
     * OpportunityRepository::paginateForAdmin() does not eager-load the
     * business/customer relationship (see the completion report), so this
     * slice renders the already-loaded, native business_id column as the
     * interim, N+1-safe ownership indicator rather than a resolved
     * business/customer name. This proves that indicator renders correctly
     * and distinctly per row without crashing across differing tenants.
     */
    public function test_opportunity_business_id_is_rendered_safely_as_the_interim_ownership_indicator(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Owned By A']);
        $this->createOpportunity($businessB, ['title' => 'Owned By B']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee((string) $businessA->id);
        $response->assertSee((string) $businessB->id);
    }

    public function test_status_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Open Fixture', 'status' => OpportunityStatus::Open->value]);
        $this->createOpportunity($business, ['title' => 'Dismissed Fixture', 'status' => OpportunityStatus::Dismissed->value]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'open']));

        $response->assertOk();
        $response->assertSee('Open Fixture');
        $response->assertDontSee('Dismissed Fixture');
    }

    public function test_freshness_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Current Fixture', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Fixture', 'freshness' => 'stale']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['freshness' => 'stale']));

        $response->assertOk();
        $response->assertSee('Stale Fixture');
        $response->assertDontSee('Current Fixture');
    }

    public function test_worker_key_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Business Advisor Fixture', 'worker_key' => 'business_advisor']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['worker_key' => 'business_advisor']));

        $response->assertOk();
        $response->assertSee('Business Advisor Fixture');
    }

    public function test_business_id_filter_is_applied(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Matching Business Fixture']);
        $this->createOpportunity($businessB, ['title' => 'Other Business Fixture']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['business_id' => $businessA->id]));

        $response->assertOk();
        $response->assertSee('Matching Business Fixture');
        $response->assertDontSee('Other Business Fixture');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'not-a-real-status']));

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
    }

    public function test_invalid_business_id_filter_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['business_id' => 'not-an-integer']));

        $response->assertRedirect();
        $response->assertSessionHasErrors('business_id');
    }

    public function test_pagination_is_deterministic_and_preserves_filters(): void
    {
        $business = $this->createBusinessForOpportunities();

        for ($i = 0; $i < 101; $i++) {
            $this->createOpportunity($business, [
                'title' => 'Bulk Opportunity ' . $i,
                'status' => OpportunityStatus::Open->value,
                'fingerprint' => hash('sha256', 'admin-bulk-' . $i),
            ]);
        }

        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'open']));
        $body = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('status=open', $body);
    }

    public function test_get_performs_no_mutation_and_dispatches_nothing(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $statusBefore = $opportunity->status;
        $freshnessBefore = $opportunity->freshness;
        $priorityScoreBefore = $opportunity->priority_score;
        $occurrenceNumberBefore = $opportunity->occurrence_number;
        $updatedAtBefore = $opportunity->updated_at;
        $transitionCountBefore = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        $executionCountBefore = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();

        Queue::fake();

        $this->get(route('admin.opportunities.index'))->assertOk();

        $fresh = $opportunity->fresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertSame($freshnessBefore, $fresh->freshness);
        $this->assertSame($priorityScoreBefore, $fresh->priority_score);
        $this->assertSame($occurrenceNumberBefore, $fresh->occurrence_number);
        $this->assertTrue($updatedAtBefore->equalTo($fresh->updated_at));
        $this->assertSame($transitionCountBefore, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($executionCountBefore, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_the_page_does_not_expose_raw_internal_identifiers(): void
    {
        $business = $this->createBusinessForOpportunities();
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
        $key = hash('sha256', 'do-not-leak-this-idempotency-key');
        $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertDontSee($opportunity->recommended_action_hash);
        $response->assertDontSee($key);
        $response->assertDontSee('+15551234567');
        $response->assertDontSee('"action_key"', false);
    }

    public function test_no_admin_mutation_or_run_inspection_route_exists_yet(): void
    {
        $this->assertTrue(Route::has('admin.opportunities.show'));
        $this->assertFalse(Route::has('admin.opportunities.snooze'));
        $this->assertFalse(Route::has('admin.opportunities.dismiss'));
        $this->assertFalse(Route::has('admin.opportunities.reopen'));
        $this->assertFalse(Route::has('admin.opportunities.runs.index'));
        $this->assertFalse(Route::has('admin.opportunities.runs.show'));
    }

    public function test_index_rows_link_to_the_detail_page(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['title' => 'Linked Fixture']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee(route('admin.opportunities.show', $opportunity->id), false);
    }

    public function test_authorized_admin_can_open_an_opportunity_belonging_to_another_customer(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['title' => 'Detail Fixture']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.show', $opportunity->id))
            ->assertOk()
            ->assertSee('Detail Fixture');
    }

    public function test_missing_opportunity_returns_404(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.show', 999999))->assertNotFound();
    }

    public function test_feature_disabled_returns_404_for_detail(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);
        config()->set('opportunity.enabled', false);

        $this->get(route('admin.opportunities.show', $opportunity->id))->assertNotFound();
    }

    public function test_admin_lacking_view_opportunities_cannot_access_detail(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.opportunities.show', $opportunity->id))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_detail(): void
    {
        $this->actingAsCustomerWithBusiness();
        $otherBusiness = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($otherBusiness);

        $this->get(route('admin.opportunities.show', $opportunity->id))->assertUnauthorized();
    }

    public function test_opportunity_ownership_information_is_rendered_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('Snap Booth Co');
        $response->assertSee('Test Customer');
        $response->assertSee((string) $business->id);
    }

    public function test_core_opportunity_diagnostic_fields_are_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, [
            'title' => 'Diagnostic Fixture Title',
            'summary' => 'Diagnostic fixture summary text.',
            'worker_key' => 'business_advisor',
            'type' => 'missing_phone',
            'status' => OpportunityStatus::Open->value,
            'freshness' => 'current',
            'priority_score' => 42,
            'impact' => 3,
            'urgency' => 2,
            'occurrence_number' => 1,
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('Diagnostic Fixture Title');
        $response->assertSee('Diagnostic fixture summary text.');
        $response->assertSee('missing_phone');
        $response->assertSee('42');
    }

    public function test_persisted_recommended_action_diagnostics_are_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributesForShow());
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('add_phone');
        $response->assertSee((string) $opportunity->action_schema_version);
        $response->assertSee($opportunity->recommended_action_hash);
        $response->assertSee('system_verified');
        $response->assertSee('Yes');
    }

    public function test_transition_history_is_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->createOpportunityTransition($opportunity, [
            'reason_code' => 'customer_dismissed',
            'to_status' => OpportunityStatus::Dismissed->value,
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('customer_dismissed');
    }

    public function test_transition_ordering_is_deterministic(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->createOpportunityTransition($opportunity, [
            'reason_code' => 'first_transition',
            'created_at' => now()->subMinutes(10),
        ]);
        $this->createOpportunityTransition($opportunity, [
            'reason_code' => 'second_transition',
            'created_at' => now(),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));
        $body = $response->getContent();

        $response->assertOk();
        $firstPosition = strpos($body, 'first_transition');
        $secondPosition = strpos($body, 'second_transition');

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    public function test_execution_history_is_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributesForShow());
        $user = $this->createUser();
        $key = hash('sha256', 'show-execution-history');
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => $key,
            'attempt_number' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'safe_result_summary' => 'Business phone updated and verified.',
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('add_phone');
        $response->assertSee($key);
        $response->assertSee('Succeeded');
        $response->assertSee('Business phone updated and verified.');
    }

    /**
     * OpportunityActionRegistry data (handler/verifier identifiers,
     * mutation classification) must never be presented as if it were
     * persisted historical fact — none of it exists on the Opportunity or
     * its execution rows, and the registry can change after the fact.
     */
    public function test_registry_only_metadata_is_not_fabricated_on_the_detail_page(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributesForShow());
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'show-no-registry-fabrication'),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('business.update_phone');
        $response->assertDontSee('business.phone_matches_parameter');
        $response->assertDontSee('Handler identifier');
        $response->assertDontSee('Verifier identifier');
        $response->assertDontSee('Mutates business data');
    }

    public function test_execution_ordering_is_deterministic(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'show-order-1'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'show-order-2'),
            'attempt_number' => 2,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));
        $body = $response->getContent();

        $response->assertOk();
        $latestKeyPosition = strpos($body, hash('sha256', 'show-order-2'));
        $earlierKeyPosition = strpos($body, hash('sha256', 'show-order-1'));

        $this->assertNotFalse($latestKeyPosition);
        $this->assertNotFalse($earlierKeyPosition);
        $this->assertLessThan($earlierKeyPosition, $latestKeyPosition);
    }

    public function test_an_execution_belonging_to_another_opportunity_is_not_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'show-scope-1')]);
        $otherOpportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'show-scope-2')]);
        $user = $this->createUser();
        $foreignKey = hash('sha256', 'show-scope-foreign-key');
        $this->createOpportunityActionExecution($otherOpportunity, $user, ['idempotency_key' => $foreignKey]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee($foreignKey);
    }

    public function test_empty_transition_and_execution_states_render_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('No transitions recorded for this opportunity.');
        $response->assertSee('No executions recorded for this opportunity.');
    }

    public function test_safe_result_and_error_summaries_may_be_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunityA = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'show-summary-a')]);
        $opportunityB = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'show-summary-b')]);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunityA, $user, [
            'idempotency_key' => hash('sha256', 'show-summary-success'),
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'safe_result_summary' => 'Business phone updated and verified.',
        ]);
        $this->createOpportunityActionExecution($opportunityB, $user, [
            'idempotency_key' => hash('sha256', 'show-summary-failure'),
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'safe_error_summary' => 'This action could not be completed.',
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $responseA = $this->get(route('admin.opportunities.show', $opportunityA->id));
        $responseB = $this->get(route('admin.opportunities.show', $opportunityB->id));

        $responseA->assertOk()->assertSee('Business phone updated and verified.');
        $responseB->assertOk()->assertSee('This action could not be completed.');
    }

    public function test_raw_exception_text_and_metadata_are_not_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, $this->addPhoneAttributesForShow());
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'show-no-raw-json'),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('"parameters"', false);
        $response->assertDontSee('+15551234567');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('SQLSTATE');
    }

    public function test_get_detail_performs_no_mutation_and_dispatches_nothing(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $statusBefore = $opportunity->status;
        $updatedAtBefore = $opportunity->updated_at;
        $transitionCountBefore = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        $executionCountBefore = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();

        Queue::fake();

        $this->get(route('admin.opportunities.show', $opportunity->id))->assertOk();

        $fresh = $opportunity->fresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertTrue($updatedAtBefore->equalTo($fresh->updated_at));
        $this->assertSame($transitionCountBefore, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($executionCountBefore, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, mixed>
     */
    private function addPhoneAttributesForShow(): array
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
        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Both the admin route group (ValidProduct) and the customer route
     * group render the shared layout, which unconditionally reads the
     * 'license' and 'custom_script' app_config rows (mirrors
     * AdminBusinessControllerTest's own identical helper).
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
