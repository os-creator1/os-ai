<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 2A — configureAction(),
 * requestApproval(), confirmApproval() only, against the real production
 * add_phone action. No retry/trusted-action/attestation/snooze/dismiss/
 * reopen/polling/admin behavior is exercised here.
 */
class OpportunityMutationHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    // -----------------------------------------------------------------
    // Route and access tests (all three mutation routes)
    // -----------------------------------------------------------------

    public function test_guest_returns_401_for_mutation_routes(): void
    {
        foreach ($this->mutationRoutes(1) as $url) {
            $this->post($url, ['value' => '+15551234567'])->assertUnauthorized();
        }
    }

    public function test_disabled_flag_returns_404_for_mutation_routes(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);
        config()->set('opportunity.enabled', false);

        foreach ($this->mutationRoutes($opportunity->id) as $url) {
            $this->post($url, ['value' => '+15551234567'])->assertNotFound();
        }
    }

    public function test_another_tenants_opportunity_returns_404_for_mutation_routes(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        $strangerOpportunity = $this->configurableOpportunity($strangerBusiness);

        foreach ($this->mutationRoutes($strangerOpportunity->id) as $url) {
            $this->post($url, ['value' => '+15551234567'])->assertNotFound();
        }
    }

    public function test_missing_opportunity_returns_404_for_mutation_routes(): void
    {
        $this->actingAsCustomerWithBusiness();

        foreach ($this->mutationRoutes(999999) as $url) {
            $this->post($url, ['value' => '+15551234567'])->assertNotFound();
        }
    }

    public function test_no_primary_business_redirects_to_onboarding_for_mutation_routes(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        foreach ($this->mutationRoutes(1) as $url) {
            $this->post($url, ['value' => '+15551234567'])
                ->assertRedirect(route('customer.onboarding.show'));
        }
    }

    // -----------------------------------------------------------------
    // Configure action
    // -----------------------------------------------------------------

    public function test_valid_value_persists_into_recommended_action_parameters_value(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $this->assertSame('+15551234567', $opportunity->fresh()->recommended_action['parameters']['value']);
    }

    public function test_valid_configure_redirects_to_the_owned_detail_page(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
    }

    public function test_configure_success_flash_text_is_exact(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $this->assertSame('success', session('status'));
        $this->assertSame('Phone number saved.', session('message'));
    }

    public function test_blank_value_redirects_with_a_value_validation_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '',
        ]);

        $response->assertSessionHasErrors('value');
        $this->assertNull($opportunity->fresh()->recommended_action['parameters']['value'] ?? null);
    }

    public function test_over_50_character_value_redirects_with_a_value_validation_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => str_repeat('1', 51),
        ]);

        $response->assertSessionHasErrors('value');
    }

    public function test_configure_validation_input_is_preserved(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '',
        ]);

        $response->assertSessionHasInput('value', '');
    }

    public function test_posted_protected_fields_are_ignored_on_configure(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
            'action_key' => 'add_email',
            'business_id' => 999999,
            'status' => 'completed',
            'approval_required' => false,
            'completion_policy' => 'customer_attested',
            'schema_version' => 99,
            'recommended_action_hash' => 'not-a-real-hash',
            'occurrence_number' => 99,
            'freshness' => 'stale',
        ]);

        $fresh = $opportunity->fresh();
        $this->assertSame('add_phone', $fresh->recommended_action['action_key']);
        $this->assertSame(OpportunityStatus::Open, $fresh->status);
        $this->assertTrue($fresh->recommended_action['approval_required']);
        $this->assertSame('system_verified', $fresh->recommended_action['completion_policy']);
        $this->assertSame(1, $fresh->recommended_action['schema_version']);
        $this->assertSame($business->id, $fresh->business_id);
    }

    public function test_wrong_status_produces_the_fixed_safe_error_on_configure(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, ['status' => OpportunityStatus::Dismissed->value]);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
    }

    /**
     * A whitespace-only value can no longer reach the domain layer at all:
     * TrimStrings + ConvertEmptyStringsToNull middleware normalizes it to
     * null before validation runs, so Laravel's own `required` rule catches
     * it first (never exercising the controller's exception mapping). Every
     * value that actually passes `required|string|max:50` post-middleware
     * also satisfies OpportunityManager::configureAction()'s own parameter
     * rule, since that rule is a strict subset of the HTTP one — so the
     * domain exception is no longer reachable from this field's input space
     * at all. Proving the controller maps it correctly therefore requires
     * mocking configureAction() itself to throw it, using an otherwise
     * ordinary, HTTP-valid value; every other route/middleware/request
     * behavior stays real.
     */
    public function test_domain_parameter_failure_maps_to_enter_a_valid_value(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $manager = Mockery::mock(OpportunityManager::class);
        $manager->shouldReceive('configureAction')
            ->once()
            ->andThrow(new InvalidOpportunityActionParametersException('Parameter [value] cannot be blank.'));
        $this->app->instance(OpportunityManager::class, $manager);

        $response = $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $response->assertSessionHasErrors(['value' => 'Enter a valid value.']);
    }

    public function test_same_value_submitted_twice_creates_no_duplicate_effects(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), ['value' => '+15551234567']);
        $hashAfterFirst = $opportunity->fresh()->recommended_action_hash;

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), ['value' => '+15551234567']);

        $this->assertSame($hashAfterFirst, $opportunity->fresh()->recommended_action_hash);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'customer_configured_action')
            ->count());
        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    // -----------------------------------------------------------------
    // Request approval
    // -----------------------------------------------------------------

    public function test_configured_open_opportunity_moves_to_awaiting_approval(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => $this->validAddPhoneAction(),
            'recommended_action_hash' => (new OpportunityActionHash())->compute($this->validAddPhoneAction()),
        ]);

        $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
    }

    public function test_request_approval_exact_redirect_and_success_flash(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => $this->validAddPhoneAction(),
            'recommended_action_hash' => (new OpportunityActionHash())->compute($this->validAddPhoneAction()),
        ]);

        $response = $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame('Approval requested.', session('message'));
    }

    public function test_request_approval_creates_one_workflow_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => $this->validAddPhoneAction(),
            'recommended_action_hash' => (new OpportunityActionHash())->compute($this->validAddPhoneAction()),
        ]);

        $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_unconfigured_action_produces_the_fixed_not_ready_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);

        $response = $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This opportunity's action isn't ready for that step yet."]);
    }

    public function test_wrong_status_produces_the_fixed_unavailable_error_on_request_approval(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);

        $response = $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
    }

    public function test_duplicate_request_approval_submission(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => $this->validAddPhoneAction(),
            'recommended_action_hash' => (new OpportunityActionHash())->compute($this->validAddPhoneAction()),
        ]);

        $this->post(route('customer.opportunities.request-approval', $opportunity->id));
        $response = $this->post(route('customer.opportunities.request-approval', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Confirm approval
    // -----------------------------------------------------------------

    public function test_awaiting_approval_moves_to_in_progress(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
    }

    public function test_exactly_one_pending_execution_is_created(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)
            ->where('status', OpportunityActionExecutionStatus::Pending->value)
            ->count());
    }

    public function test_execution_identity_snapshot_fields_are_correct(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $execution = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($execution);
        $this->assertSame('add_phone', $execution->action_key);
        $this->assertSame($opportunity->recommended_action_hash, $execution->recommended_action_hash);
        $this->assertSame(1, $execution->action_schema_version);
        $this->assertSame(1, $execution->occurrence_number);
        $this->assertSame($business->customer->user_id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
    }

    public function test_exactly_one_workflow_transition_is_created_for_confirmation(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'customer_confirmed_approval')
            ->count());
    }

    public function test_execute_opportunity_action_is_queued_once_with_the_execution_id(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $execution = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->first();

        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
        Queue::assertPushed(ExecuteOpportunityAction::class, function (ExecuteOpportunityAction $job) use ($execution) {
            return $job->executionId === $execution->id;
        });
    }

    public function test_confirm_approval_exact_success_redirect_and_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        Queue::fake();

        $response = $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame('Approval confirmed. The action has started.', session('message'));
    }

    public function test_duplicate_while_pending_is_idempotent(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        Queue::fake();

        $response = $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_while_running_is_idempotent(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Running);
        Queue::fake();

        $response = $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_completed_opportunity_produces_the_fixed_unavailable_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business, ['status' => OpportunityStatus::Completed->value]);

        $response = $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
    }

    public function test_inconsistent_in_progress_execution_state_produces_the_fixed_refresh_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->awaitingApprovalOpportunity($business, ['status' => OpportunityStatus::InProgress->value]);

        $response = $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));

        $response->assertSessionHasErrors([
            'opportunity' => "This opportunity's execution state has changed. Please refresh and try again.",
        ]);
    }

    // -----------------------------------------------------------------
    // End-to-end
    // -----------------------------------------------------------------

    public function test_full_add_phone_configure_approve_confirm_workflow(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business);
        Queue::fake();

        $this->get(route('customer.opportunities.show', $opportunity->id))
            ->assertOk()
            ->assertSee('Save phone number')
            ->assertDontSee('Request approval')
            ->assertDontSee('Confirm and start');

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);
        $this->assertSame('+15551234567', $opportunity->fresh()->recommended_action['parameters']['value']);

        $this->get(route('customer.opportunities.show', $opportunity->id))
            ->assertOk()
            ->assertSee('Request approval');

        $this->post(route('customer.opportunities.request-approval', $opportunity->id));
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);

        $this->get(route('customer.opportunities.show', $opportunity->id))
            ->assertOk()
            ->assertSee('Confirm and start');

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);

        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'customer_confirmed_approval')
            ->count());
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
    }

    // -----------------------------------------------------------------
    // Safe output
    // -----------------------------------------------------------------

    public function test_error_responses_do_not_leak_internal_details(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->configurableOpportunity($business, ['status' => OpportunityStatus::Dismissed->value]);

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), [
            'value' => '+15551234567',
        ]);

        $errors = session('errors')->getBag('default')->all();
        $body = implode(' ', $errors);

        $this->assertStringNotContainsString('InvalidOpportunityStateException', $body);
        $this->assertStringNotContainsString('InvalidOpportunityActionParametersException', $body);
        $this->assertStringNotContainsString((string) $opportunity->id, $body);
        $this->assertStringNotContainsString($opportunity->recommended_action_hash ?? '', $body);
        $this->assertStringNotContainsString('business.update_phone', $body);
        $this->assertStringNotContainsString('business.phone_matches_parameter', $body);
        $this->assertSame(["This action isn't available for this opportunity right now."], $errors);
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function mutationRoutes(int $opportunityId): array
    {
        return [
            route('customer.opportunities.configure-action', $opportunityId),
            route('customer.opportunities.request-approval', $opportunityId),
            route('customer.opportunities.confirm-approval', $opportunityId),
        ];
    }

    /**
     * An open, add_phone-targeted, not-yet-configured Opportunity — mirrors
     * OpportunityManagerConfigureActionTest's own fixture exactly.
     */
    private function configurableOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $initialAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => [],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $initialAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($initialAction),
            'action_schema_version' => 1,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function validAddPhoneAction(mixed $value = '+15551234567'): array
    {
        return [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => $value],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
    }

    private function awaitingApprovalOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $recommendedAction = $overrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $overrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::AwaitingApproval->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $overrides));
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function inProgressOpportunityWithExecution(
        Business $business,
        OpportunityActionExecutionStatus $executionStatus,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $recommendedAction = $opportunityOverrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $opportunityOverrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        $opportunity = $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::InProgress->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $opportunityOverrides));

        $user = $this->createUser();

        $execution = $this->createOpportunityActionExecution($opportunity, $user, array_merge([
            'action_key' => 'add_phone',
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => $executionStatus->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
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
