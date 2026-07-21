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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 2B — snooze(), dismiss(),
 * reopen() only. No retry/trusted-action/attestation/polling/admin/domain
 * behavior is exercised here.
 */
class OpportunityManualStateHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    // -----------------------------------------------------------------
    // Access tests (all three manual-state routes)
    // -----------------------------------------------------------------

    public function test_guest_returns_401_for_manual_state_routes(): void
    {
        foreach ($this->manualStateRoutes(1) as $url) {
            $this->post($url, ['duration' => '1_day'])->assertUnauthorized();
        }
    }

    public function test_disabled_flag_returns_404_for_manual_state_routes(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');
        config()->set('opportunity.enabled', false);

        foreach ($this->manualStateRoutes($opportunity->id) as $url) {
            $this->post($url, ['duration' => '1_day'])->assertNotFound();
        }
    }

    public function test_another_tenants_opportunity_returns_404_for_manual_state_routes(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        $strangerOpportunity = $this->opportunityWithStatus($strangerBusiness, 'open');

        foreach ($this->manualStateRoutes($strangerOpportunity->id) as $url) {
            $this->post($url, ['duration' => '1_day'])->assertNotFound();
        }
    }

    public function test_missing_opportunity_returns_404_for_manual_state_routes(): void
    {
        $this->actingAsCustomerWithBusiness();

        foreach ($this->manualStateRoutes(999999) as $url) {
            $this->post($url, ['duration' => '1_day'])->assertNotFound();
        }
    }

    public function test_no_primary_business_redirects_to_onboarding_for_manual_state_routes(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        foreach ($this->manualStateRoutes(1) as $url) {
            $this->post($url, ['duration' => '1_day'])
                ->assertRedirect(route('customer.onboarding.show'));
        }
    }

    // -----------------------------------------------------------------
    // Snooze
    // -----------------------------------------------------------------

    public function test_open_opportunity_can_be_snoozed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $this->assertSame('snoozed', $opportunity->fresh()->status->value);
    }

    public function test_awaiting_approval_opportunity_can_be_snoozed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'awaiting_approval');

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $this->assertSame('snoozed', $opportunity->fresh()->status->value);
    }

    public function test_each_fixed_duration_maps_to_the_correct_future_timestamp(): void
    {
        $now = Carbon::create(2026, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        try {
            $business = $this->actingAsCustomerWithBusiness();

            $oneDay = $this->opportunityWithStatus($business, 'open', ['fingerprint' => hash('sha256', 'snooze-1-day')]);
            $this->post(route('customer.opportunities.snooze', $oneDay->id), ['duration' => '1_day']);
            $this->assertTrue($now->copy()->addDay()->equalTo($oneDay->fresh()->snoozed_until));

            $threeDays = $this->opportunityWithStatus($business, 'open', ['fingerprint' => hash('sha256', 'snooze-3-days')]);
            $this->post(route('customer.opportunities.snooze', $threeDays->id), ['duration' => '3_days']);
            $this->assertTrue($now->copy()->addDays(3)->equalTo($threeDays->fresh()->snoozed_until));

            $oneWeek = $this->opportunityWithStatus($business, 'open', ['fingerprint' => hash('sha256', 'snooze-1-week')]);
            $this->post(route('customer.opportunities.snooze', $oneWeek->id), ['duration' => '1_week']);
            $this->assertTrue($now->copy()->addWeek()->equalTo($oneWeek->fresh()->snoozed_until));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_snooze_creates_exactly_one_workflow_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'customer_snoozed')
            ->count());
    }

    public function test_snooze_exact_success_redirect_and_flash(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $response = $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame('Opportunity snoozed.', session('message'));
    }

    public function test_invalid_duration_redirects_with_duration_validation_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $response = $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => 'not-a-real-duration']);

        $response->assertSessionHasErrors(['duration' => 'Choose how long to snooze this opportunity for.']);
        $this->assertSame('open', $opportunity->fresh()->status->value);
    }

    public function test_missing_duration_redirects_with_duration_validation_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $response = $this->post(route('customer.opportunities.snooze', $opportunity->id), []);

        $response->assertSessionHasErrors(['duration' => 'Choose how long to snooze this opportunity for.']);
    }

    public function test_client_supplied_protected_fields_are_ignored_on_snooze(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.snooze', $opportunity->id), [
            'duration' => '1_day',
            'snoozed_until' => '2099-01-01T00:00:00Z',
            'business_id' => 999999,
            'status' => 'completed',
            'reason' => 'arbitrary customer text',
        ]);

        $fresh = $opportunity->fresh();
        $this->assertSame('snoozed', $fresh->status->value);
        $this->assertLessThan(2099, $fresh->snoozed_until->year);
        $this->assertSame($business->id, $fresh->business_id);
    }

    public function test_already_snoozed_submission_returns_the_fixed_safe_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $response = $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
    }

    public function test_wrong_state_snooze_returns_the_fixed_safe_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (['in_progress', 'completed', 'dismissed'] as $status) {
            $opportunity = $this->opportunityWithStatus($business, $status, ['fingerprint' => hash('sha256', 'snooze-wrong-' . $status)]);

            $response = $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

            $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        }
    }

    public function test_failed_snooze_request_creates_no_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed');

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    // -----------------------------------------------------------------
    // Dismiss
    // -----------------------------------------------------------------

    public function test_open_opportunity_can_be_dismissed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertSame('dismissed', $opportunity->fresh()->status->value);
    }

    public function test_awaiting_approval_opportunity_can_be_dismissed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'awaiting_approval');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertSame('dismissed', $opportunity->fresh()->status->value);
    }

    public function test_dismissed_at_is_set(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertNotNull($opportunity->fresh()->dismissed_at);
    }

    public function test_dismiss_creates_exactly_one_workflow_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->where('reason_code', 'customer_dismissed')
            ->count());
    }

    public function test_dismiss_exact_success_redirect_and_flash(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $response = $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame('Opportunity dismissed.', session('message'));
    }

    public function test_duplicate_dismissal_returns_the_fixed_safe_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));
        $response = $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_wrong_state_dismiss_returns_the_fixed_safe_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (['snoozed', 'completed', 'in_progress'] as $status) {
            $opportunity = $this->opportunityWithStatus($business, $status, ['fingerprint' => hash('sha256', 'dismiss-wrong-' . $status)]);

            $response = $this->post(route('customer.opportunities.dismiss', $opportunity->id));

            $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        }
    }

    public function test_failed_dismiss_request_creates_no_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'in_progress');

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    // -----------------------------------------------------------------
    // Reopen
    // -----------------------------------------------------------------

    public function test_reopen_snoozed_to_open_clears_snoozed_until(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertNull($fresh->snoozed_until);
    }

    public function test_reopen_dismissed_to_open_clears_dismissed_at(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'dismissed', ['dismissed_at' => now()->subDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertNull($fresh->dismissed_at);
    }

    public function test_reopen_completed_to_open_clears_completed_at(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed', ['completed_at' => now()->subDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertNull($fresh->completed_at);
    }

    public function test_all_three_workflow_timestamps_are_null_after_successful_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed', ['completed_at' => now()->subDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->snoozed_until);
        $this->assertNull($fresh->dismissed_at);
        $this->assertNull($fresh->completed_at);
    }

    public function test_occurrence_number_remains_unchanged_after_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed', [
            'completed_at' => now()->subDay(),
            'occurrence_number' => 3,
        ]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $this->assertSame(3, $opportunity->fresh()->occurrence_number);
    }

    public function test_reason_code_is_exactly_customer_reopened(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame('customer_reopened', $transition->reason_code);
    }

    public function test_reopen_creates_exactly_one_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_reopen_exact_success_redirect_and_flash(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $response = $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $response->assertRedirect(route('customer.opportunities.show', $opportunity->id));
        $this->assertSame('success', session('status'));
        $this->assertSame('Opportunity reopened.', session('message'));
    }

    public function test_wrong_state_reopen_returns_the_fixed_safe_error(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        foreach (['open', 'awaiting_approval', 'in_progress'] as $status) {
            $opportunity = $this->opportunityWithStatus($business, $status, ['fingerprint' => hash('sha256', 'reopen-wrong-' . $status)]);

            $response = $this->post(route('customer.opportunities.reopen', $opportunity->id));

            $response->assertSessionHasErrors(['opportunity' => "This action isn't available for this opportunity right now."]);
        }
    }

    public function test_failed_reopen_request_creates_no_transition(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    // -----------------------------------------------------------------
    // Stale eligibility
    // -----------------------------------------------------------------

    public function test_stale_open_can_be_snoozed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open', ['freshness' => 'stale']);

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $fresh = $opportunity->fresh();
        $this->assertSame('snoozed', $fresh->status->value);
        $this->assertSame('stale', $fresh->freshness->value);
    }

    public function test_stale_open_can_be_dismissed(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open', ['freshness' => 'stale']);

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertSame('dismissed', $fresh->status->value);
        $this->assertSame('stale', $fresh->freshness->value);
    }

    public function test_stale_snoozed_can_be_reopened(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', [
            'freshness' => 'stale',
            'snoozed_until' => now()->addDay(),
        ]);

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $fresh = $opportunity->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertSame('stale', $fresh->freshness->value);
    }

    // -----------------------------------------------------------------
    // Execution and job safety
    // -----------------------------------------------------------------

    public function test_dismiss_does_not_create_or_modify_execution_rows(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');
        $user = $this->createUser();
        $execution = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'pre-existing-failed-dismiss'),
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        Queue::fake();

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_snooze_does_not_create_or_dispatch_anything(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');
        Queue::fake();

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);

        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_reopen_does_not_create_or_modify_pre_existing_execution_rows(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed', ['completed_at' => now()->subDay()]);
        $user = $this->createUser();
        $execution = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'pre-existing-succeeded-reopen'),
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);
        Queue::fake();

        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_no_execute_opportunity_action_job_is_ever_dispatched_by_manual_state_routes(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');
        Queue::fake();

        $this->post(route('customer.opportunities.snooze', $opportunity->id), ['duration' => '1_day']);
        $this->post(route('customer.opportunities.reopen', $opportunity->id));

        $other = $this->opportunityWithStatus($business, 'open', ['fingerprint' => hash('sha256', 'manual-state-dismiss-job-safety')]);
        $this->post(route('customer.opportunities.dismiss', $other->id));

        Queue::assertNotPushed(ExecuteOpportunityAction::class);
    }

    // -----------------------------------------------------------------
    // Form visibility
    // -----------------------------------------------------------------

    public function test_open_shows_snooze_and_dismiss_but_not_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'open');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertSee('Snooze opportunity');
        $response->assertSee('Dismiss opportunity');
        $response->assertDontSee('Reopen opportunity');
    }

    public function test_awaiting_approval_shows_snooze_and_dismiss_but_not_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'awaiting_approval');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertSee('Snooze opportunity');
        $response->assertSee('Dismiss opportunity');
        $response->assertDontSee('Reopen opportunity');
    }

    public function test_snoozed_shows_only_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'snoozed', ['snoozed_until' => now()->addDay()]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertSee('Reopen opportunity');
        $response->assertDontSee('Snooze opportunity');
        $response->assertDontSee('Dismiss opportunity');
    }

    public function test_dismissed_shows_only_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'dismissed', ['dismissed_at' => now()->subDay()]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertSee('Reopen opportunity');
        $response->assertDontSee('Snooze opportunity');
        $response->assertDontSee('Dismiss opportunity');
    }

    public function test_completed_shows_only_reopen(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'completed', ['completed_at' => now()->subDay()]);

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertSee('Reopen opportunity');
        $response->assertDontSee('Snooze opportunity');
        $response->assertDontSee('Dismiss opportunity');
    }

    public function test_in_progress_shows_no_manual_state_forms(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'in_progress');

        $response = $this->get(route('customer.opportunities.show', $opportunity->id));

        $response->assertDontSee('Snooze opportunity');
        $response->assertDontSee('Dismiss opportunity');
        $response->assertDontSee('Reopen opportunity');
    }

    // -----------------------------------------------------------------
    // Safe output
    // -----------------------------------------------------------------

    public function test_error_responses_do_not_leak_internal_details(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->opportunityWithStatus($business, 'in_progress');
        $user = $this->createUser();
        $execution = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'safe-output-manual-state'),
            'status' => OpportunityActionExecutionStatus::Running->value,
        ]);

        $this->post(route('customer.opportunities.dismiss', $opportunity->id));

        $errors = session('errors')->getBag('default')->all();
        $body = implode(' ', $errors);

        $this->assertStringNotContainsString('InvalidOpportunityStateException', $body);
        $this->assertStringNotContainsString((string) $opportunity->id, $body);
        $this->assertStringNotContainsString((string) $execution->id, $body);
        $this->assertStringNotContainsString($execution->idempotency_key, $body);
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
    private function manualStateRoutes(int $opportunityId): array
    {
        return [
            route('customer.opportunities.snooze', $opportunityId),
            route('customer.opportunities.dismiss', $opportunityId),
            route('customer.opportunities.reopen', $opportunityId),
        ];
    }

    private function opportunityWithStatus(Business $business, string $status, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge(['status' => $status], $overrides));
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
