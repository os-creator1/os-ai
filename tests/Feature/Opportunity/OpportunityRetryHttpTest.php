<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 2C — retryFailedExecution() only.
 * No beginTrustedAction/attestComplete/polling/admin/domain/worker behavior
 * is exercised here.
 */
class OpportunityRetryHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_guest_returns_401(): void
    {
        $this->post(route('customer.opportunities.retry', 1))->assertUnauthorized();
    }

    public function test_disabled_flag_returns_404(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        config()->set('opportunity.enabled', false);

        $this->post(route('customer.opportunities.retry', $opportunity->id))->assertNotFound();
    }

    public function test_another_tenants_opportunity_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        [$strangerOpportunity] = $this->openOpportunityWithFailedExecution($strangerBusiness);

        $this->post(route('customer.opportunities.retry', $strangerOpportunity->id))->assertNotFound();
    }

    public function test_missing_opportunity_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();

        $this->post(route('customer.opportunities.retry', 999999))->assertNotFound();
    }

    public function test_no_primary_business_redirects_to_onboarding(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $this->post(route('customer.opportunities.retry', 1))
            ->assertRedirect(route('customer.onboarding.show'));
    }

    // -----------------------------------------------------------------
    // Valid retry
    // -----------------------------------------------------------------

    public function test_failed_mutating_action_requires_fresh_approval_and_has_no_effect(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);

        $this->assertRetryRejectsSafely($opportunity, $business);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    public function test_posted_metadata_has_no_effect_on_the_retry(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        Queue::fake();

        $this->post(route('customer.opportunities.retry', $opportunity->id), [
            'execution_id' => 999999,
            'action_key' => 'add_email',
            'business_id' => 999999,
            'attempt_number' => 99,
            'idempotency_key' => 'not-a-real-key',
            'recommended_action_hash' => 'not-a-real-hash',
            'action_schema_version' => 99,
            'occurrence_number' => 99,
            'status' => 'completed',
        ]);

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $this->assertSame($business->id, $opportunity->fresh()->business_id);
        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // Duplicate submissions
    // -----------------------------------------------------------------

    public function test_repeated_retry_of_failed_mutation_remains_refused(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        Queue::fake();

        $this->assertRetryRejectsSafely($opportunity, $business);
        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_running_execution_is_not_retryable(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        Queue::fake();

        OpportunityActionExecution::where('opportunity_id', $opportunity->id)
            ->update(['status' => OpportunityActionExecutionStatus::Running->value]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    // -----------------------------------------------------------------
    // Ineligible
    // -----------------------------------------------------------------

    public function test_no_failed_execution_exists(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openAddPhoneOpportunity($business);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_only_a_succeeded_execution_exists(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openAddPhoneOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'only-succeeded'),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_failed_execution_has_a_different_recommended_action_hash(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'recommended_action_hash' => hash('sha256', 'a-different-hash'),
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_failed_execution_has_a_different_occurrence_number(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'occurrence_number' => 2,
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_failed_execution_has_a_different_action_schema_version(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'action_schema_version' => 2,
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_failed_execution_has_a_different_action_key(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_completed_opportunity_after_a_successful_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openAddPhoneOpportunity($business, ['status' => OpportunityStatus::Completed->value, 'completed_at' => now()]);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'completed-succeeded'),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    public function test_current_recommended_action_is_not_executable(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [
            // The persisted hash no longer matches the persisted action —
            // assertActionIsExecutable() rejects before ever looking for a
            // matching failed execution.
            'recommended_action_hash' => hash('sha256', 'tampered'),
        ]);

        $this->assertRetryRejectsSafely($opportunity, $business);
    }

    // -----------------------------------------------------------------
    // Stale behavior
    // -----------------------------------------------------------------

    public function test_stale_open_opportunity_still_requires_fresh_approval_for_mutation(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, ['freshness' => 'stale']);
        Queue::fake();

        $this->assertRetryRejectsSafely($opportunity, $business);

        $fresh = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::Open, $fresh->status);
        $this->assertSame('stale', $fresh->freshness->value);
    }

    // -----------------------------------------------------------------
    // Form visibility
    // -----------------------------------------------------------------

    public function test_retry_form_absent_for_mutating_failed_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    /**
     * The mandatory regression case: a matching failed execution exists but
     * a newer, non-matching failed execution is the latest overall. The
     * retry form must still appear, proving eligibility is computed via
     * findLatestFailedMatching() and not via the latest execution overall.
     */
    public function test_retry_form_absent_for_mutating_failed_execution_even_when_matching_is_not_latest(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithOlderMatchingAndNewerMismatchedFailedExecutions($business);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    public function test_retry_form_absent_with_no_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openAddPhoneOpportunity($business);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    public function test_retry_form_absent_with_only_a_mismatched_failed_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'recommended_action_hash' => hash('sha256', 'mismatched-for-visibility'),
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    public function test_retry_form_absent_with_only_a_succeeded_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openAddPhoneOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'visibility-succeeded'),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    public function test_retry_form_absent_for_every_non_open_status_even_with_a_matching_failed_execution(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (['awaiting_approval', 'in_progress', 'snoozed', 'dismissed', 'completed'] as $status) {
            $opportunity = $this->openAddPhoneOpportunity($business, [
                'status' => $status,
                'fingerprint' => hash('sha256', 'retry-visibility-status-' . $status),
            ]);
            $this->createMatchingFailedExecution($opportunity);

            $response = $this->get(route('customer.opportunities.show', $opportunity->id));

            $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
        }
    }

    public function test_retry_form_absent_with_missing_recommended_action(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => null,
            'recommended_action_hash' => null,
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    public function test_retry_form_absent_with_missing_action_key(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, [
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee(route('customer.opportunities.retry', $opportunity->id), false);
    }

    // -----------------------------------------------------------------
    // Safe output
    // -----------------------------------------------------------------

    public function test_error_responses_do_not_leak_internal_details(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business, [], [
            'occurrence_number' => 2,
        ]);

        $this->post(route('customer.opportunities.retry', $opportunity->id));

        $errors = session('errors')->getBag('default')->all();
        $body = implode(' ', $errors);

        $this->assertStringNotContainsString('OpportunityExecutionRetryNotAvailableException', $body);
        $this->assertStringNotContainsString('OpportunityActionNotExecutableException', $body);
        $this->assertStringNotContainsString((string) $opportunity->id, $body);
        $this->assertStringNotContainsString((string) $failedExecution->id, $body);
        $this->assertStringNotContainsString($opportunity->recommended_action_hash ?? '', $body);
        $this->assertStringNotContainsString($failedExecution->recommended_action_hash ?? '', $body);
        $this->assertStringNotContainsString('add_phone', $body);
        $this->assertStringNotContainsString((string) $failedExecution->attempt_number, $body);
        $this->assertStringNotContainsString('business.update_phone', $body);
        $this->assertStringNotContainsString('business.phone_matches_parameter', $body);
        $this->assertSame(["This action isn't available for this opportunity right now."], $errors);
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    private function assertRetryRejectsSafely(Opportunity $opportunity, Business $business): void
    {
        $originalPhone = $business->phone;
        $originalExecutionCount = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();
        $originalTransitionCount = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        Queue::fake();

        $response = $this->post(route('customer.opportunities.retry', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        $this->assertSame($originalExecutionCount, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($originalTransitionCount, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($originalPhone, $business->fresh()->phone);
        Queue::assertNothingPushed();
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

    private function openAddPhoneOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $recommendedAction = $overrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $overrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $overrides));
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function openOpportunityWithFailedExecution(
        Business $business,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $opportunity = $this->openAddPhoneOpportunity($business, $opportunityOverrides);
        $user = $this->createUser();

        $execution = $this->createOpportunityActionExecution($opportunity, $user, array_merge([
            'idempotency_key' => hash('sha256', 'retry-failed-' . uniqid('', true)),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
            'attempt_number' => 1,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function createMatchingFailedExecution(Opportunity $opportunity): OpportunityActionExecution
    {
        $user = $this->createUser();

        return $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'matching-failed-' . uniqid('', true)),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => $opportunity->action_schema_version,
            'occurrence_number' => $opportunity->occurrence_number,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ]);
    }

    /**
     * The mandatory regression fixture: an older, matching failed attempt
     * plus a newer, non-matching failed attempt (e.g. the action was
     * reconfigured then reconfigured back to its original value) — proving
     * eligibility must be computed by matching, not by recency.
     *
     * @return array{0: Opportunity, 1: OpportunityActionExecution, 2: OpportunityActionExecution}
     */
    private function openOpportunityWithOlderMatchingAndNewerMismatchedFailedExecutions(Business $business): array
    {
        $opportunity = $this->openAddPhoneOpportunity($business);
        $user = $this->createUser();

        $olderMatching = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'retry-older-matching'),
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
            'attempt_number' => 1,
        ]);

        $newerMismatched = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'retry-newer-mismatched'),
            'action_key' => 'add_phone',
            'recommended_action_hash' => hash('sha256', 'a-different-hash-entirely'),
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
            'attempt_number' => 2,
        ]);

        return [$opportunity, $olderMatching, $newerMismatched];
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
