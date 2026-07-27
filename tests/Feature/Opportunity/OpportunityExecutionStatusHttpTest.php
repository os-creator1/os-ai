<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
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
 * RFC-002 Milestone 4 customer HTTP Slice 2D — the execution-status polling
 * endpoint and its detail-page markup only. No JavaScript-behavior/browser
 * test is included — this codebase has no such infrastructure; only the
 * endpoint's JSON contract and the presence/absence/content of the
 * server-rendered polling markup are asserted.
 */
class OpportunityExecutionStatusHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const STOPPED_MESSAGE = 'Automatic status updates stopped. Refresh this page to try again.';

    private const STATUS_CHANGED_MESSAGE = 'The opportunity status changed. Refreshing this page.';

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
        $this->get(route('customer.opportunities.execution-status', 1))->assertUnauthorized();
    }

    public function test_disabled_flag_returns_404(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');
        config()->set('opportunity.enabled', false);

        $this->get(route('customer.opportunities.execution-status', $opportunity->id))->assertNotFound();
    }

    public function test_another_tenants_opportunity_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        [$strangerOpportunity] = $this->opportunityWithExecution($strangerBusiness, 'in_progress', 'pending');

        $this->get(route('customer.opportunities.execution-status', $strangerOpportunity->id))->assertNotFound();
    }

    public function test_missing_opportunity_returns_404(): void
    {
        $this->actingAsCustomerWithBusiness();

        $this->get(route('customer.opportunities.execution-status', 999999))->assertNotFound();
    }

    public function test_no_primary_business_returns_404(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $this->get(route('customer.opportunities.execution-status', 1))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Safe responses
    // -----------------------------------------------------------------

    public function test_in_progress_pending_is_not_terminal_with_queued_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertExactJson([
            'opportunity_status' => 'in_progress',
            'execution_status' => 'pending',
            'message' => 'This action is currently queued.',
            'is_terminal' => false,
        ]);
    }

    public function test_in_progress_running_is_not_terminal_with_in_progress_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'running');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertExactJson([
            'opportunity_status' => 'in_progress',
            'execution_status' => 'running',
            'message' => 'This action is currently in progress.',
            'is_terminal' => false,
        ]);
    }

    public function test_completed_succeeded_is_terminal_with_safe_result_summary(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'completed', 'succeeded', [
            'safe_result_summary' => 'Business phone updated and verified.',
        ]);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson([
            'opportunity_status' => 'completed',
            'execution_status' => 'succeeded',
            'message' => 'Business phone updated and verified.',
            'is_terminal' => true,
        ]);
    }

    public function test_succeeded_without_safe_summary_uses_the_fixed_fallback(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'completed', 'succeeded', [
            'safe_result_summary' => null,
        ]);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['message' => 'This action completed successfully.']);
    }

    public function test_open_failed_is_terminal_with_safe_error_summary(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'open', 'failed', [
            'safe_error_summary' => 'Business update could not be verified.',
        ]);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson([
            'opportunity_status' => 'open',
            'execution_status' => 'failed',
            'message' => 'Business update could not be verified.',
            'is_terminal' => true,
        ]);
    }

    public function test_failed_without_safe_summary_uses_the_fixed_fallback(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'open', 'failed', [
            'safe_error_summary' => null,
        ]);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['message' => 'This action could not be completed.']);
    }

    public function test_any_status_with_no_execution_is_terminal_with_neutral_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (['open', 'awaiting_approval', 'in_progress', 'snoozed', 'dismissed', 'completed'] as $status) {
            [$opportunity] = $this->opportunityWithExecution($business, $status, null, [], 'no-exec-' . $status);

            $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

            $response->assertOk();
            $response->assertExactJson([
                'opportunity_status' => $status,
                'execution_status' => null,
                'message' => 'No execution is currently in progress.',
                'is_terminal' => true,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Inconsistent combinations
    // -----------------------------------------------------------------

    public function test_in_progress_succeeded_is_terminal(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'succeeded');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_in_progress_failed_is_terminal(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'failed');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_in_progress_no_execution_is_terminal(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', null);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_open_pending_is_terminal_with_status_changed_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'open', 'pending');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true, 'message' => self::STATUS_CHANGED_MESSAGE]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_completed_running_is_terminal_with_status_changed_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'completed', 'running');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true, 'message' => self::STATUS_CHANGED_MESSAGE]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_snoozed_pending_is_terminal_with_status_changed_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'snoozed', 'pending', ['occurrence_number' => 1]);

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true, 'message' => self::STATUS_CHANGED_MESSAGE]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_dismissed_running_is_terminal_with_status_changed_message(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'dismissed', 'running');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $response->assertOk();
        $response->assertJson(['is_terminal' => true, 'message' => self::STATUS_CHANGED_MESSAGE]);
        $this->assertPollingMarkerAbsent($opportunity);
    }

    // -----------------------------------------------------------------
    // JSON safety
    // -----------------------------------------------------------------

    public function test_response_has_exactly_the_four_allowlisted_keys(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $this->assertSame(
            ['opportunity_status', 'execution_status', 'message', 'is_terminal'],
            array_keys($response->json())
        );
    }

    public function test_execution_status_is_a_string_or_null(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$withExecution] = $this->opportunityWithExecution($business, 'in_progress', 'pending', [], 'type-check-with');
        [$withoutExecution] = $this->opportunityWithExecution($business, 'open', null, [], 'type-check-without');

        $this->assertIsString($this->getJson(route('customer.opportunities.execution-status', $withExecution->id))->json('execution_status'));
        $this->assertNull($this->getJson(route('customer.opportunities.execution-status', $withoutExecution->id))->json('execution_status'));
    }

    public function test_no_internal_identifiers_or_metadata_appear_in_the_response(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity, $execution] = $this->opportunityWithExecution($business, 'in_progress', 'running');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));
        $body = $response->getContent();

        $this->assertStringNotContainsString((string) $opportunity->id, $body);
        $this->assertStringNotContainsString((string) $execution->id, $body);
        $this->assertStringNotContainsString('add_phone', $body);
        $this->assertStringNotContainsString($execution->recommended_action_hash ?? '', $body);
        $this->assertStringNotContainsString((string) $execution->action_schema_version, $body);
        $this->assertStringNotContainsString((string) $execution->occurrence_number, $body);
        $this->assertStringNotContainsString((string) $execution->attempt_number, $body);
        $this->assertStringNotContainsString($execution->idempotency_key, $body);
        $this->assertStringNotContainsString('business.update_phone', $body);
        $this->assertStringNotContainsString('business.phone_matches_parameter', $body);
    }

    public function test_no_raw_exception_text_leaks(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'open', 'failed', [
            'safe_error_summary' => null,
        ]);

        $body = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id))->getContent();

        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('.php', $body);
    }

    public function test_cache_control_contains_no_store(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_repeated_get_performs_no_mutation_and_dispatches_no_job(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity, $execution] = $this->opportunityWithExecution($business, 'in_progress', 'pending');
        Queue::fake();

        $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));
        $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));
        $this->getJson(route('customer.opportunities.execution-status', $opportunity->id));

        $this->assertSame('in_progress', $opportunity->fresh()->status->value);
        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // Detail-page markup
    // -----------------------------------------------------------------

    public function test_active_pending_page_contains_the_full_polling_surface(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('id="opportunity-execution-poller"', false);
        // @json() escapes forward slashes by default, so the embedded URL
        // must be matched in its JSON-encoded form, not the raw route()
        // string.
        $response->assertSee(json_encode(route('customer.opportunities.execution-status', $opportunity->id)), false);
        $response->assertSee('role="status"', false);
        $response->assertSee('aria-live="polite"', false);
        $response->assertSee('Refresh status');
    }

    public function test_active_running_page_contains_the_full_polling_surface(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'running');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee('id="opportunity-execution-poller"', false);
        // @json() escapes forward slashes by default, so the embedded URL
        // must be matched in its JSON-encoded form, not the raw route()
        // string.
        $response->assertSee(json_encode(route('customer.opportunities.execution-status', $opportunity->id)), false);
    }

    public function test_terminal_states_do_not_contain_the_polling_marker(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        [$succeeded] = $this->opportunityWithExecution($business, 'completed', 'succeeded', [], 'terminal-succeeded');
        [$failed] = $this->opportunityWithExecution($business, 'open', 'failed', [], 'terminal-failed');

        $this->assertPollingMarkerAbsent($succeeded);
        $this->assertPollingMarkerAbsent($failed);
    }

    public function test_in_progress_with_no_execution_does_not_contain_the_polling_marker(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', null);

        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_inconsistent_non_in_progress_with_active_execution_does_not_contain_the_polling_marker(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'open', 'pending', [], 'inconsistent-marker');

        $this->assertPollingMarkerAbsent($opportunity);
    }

    public function test_rendered_polling_html_contains_no_internal_identifiers(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity, $execution] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));
        $body = $response->getContent();

        // Small integer identity fields (id/schema_version/occurrence_number)
        // are deliberately not asserted here: in a fixture where they equal
        // "1", checking a full rendered page (versions, indexes, unrelated
        // markup) for that bare digit is not a meaningful leak check. Only
        // genuinely distinctive values are asserted.
        $this->assertStringNotContainsString($execution->recommended_action_hash ?? '', $body);
        $this->assertStringNotContainsString('add_phone', $body);
        $this->assertStringNotContainsString($execution->idempotency_key, $body);
    }

    public function test_rendered_script_contains_the_required_behavioral_markers(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));
        $body = $response->getContent();

        $this->assertStringContainsString('application/json', $body);
        $this->assertStringContainsString('X-Requested-With', $body);
        $this->assertStringContainsString('textContent', $body);
        $this->assertStringContainsString('document.hidden', $body);
        $this->assertStringContainsString('visibilitychange', $body);
        $this->assertStringContainsString(self::STOPPED_MESSAGE, $body);
    }

    public function test_rendered_script_never_uses_innerHTML(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        [$opportunity] = $this->opportunityWithExecution($business, 'in_progress', 'pending');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee('innerHTML', false);
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    private function assertPollingMarkerAbsent(Opportunity $opportunity): void
    {
        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertDontSee('id="opportunity-execution-poller"', false);
    }

    /**
     * @return array{0: Opportunity, 1: ?OpportunityActionExecution}
     */
    private function opportunityWithExecution(
        Business $business,
        string $opportunityStatus,
        ?string $executionStatus,
        array $executionOverrides = [],
        ?string $fingerprintSuffix = null
    ): array {
        $opportunity = $this->createOpportunity($business, array_merge(
            ['status' => $opportunityStatus],
            $fingerprintSuffix !== null ? ['fingerprint' => hash('sha256', 'exec-status-' . $fingerprintSuffix)] : []
        ));

        if ($executionStatus === null) {
            return [$opportunity, null];
        }

        $user = $this->createUser();
        $execution = $this->createOpportunityActionExecution($opportunity, $user, array_merge([
            'idempotency_key' => hash('sha256', 'exec-status-execution-' . uniqid('', true)),
            'status' => $executionStatus,
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
