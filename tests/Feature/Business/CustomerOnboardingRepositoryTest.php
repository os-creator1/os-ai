<?php

namespace Tests\Feature\Business;

use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class CustomerOnboardingRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_start_for_customer_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);

        $first = $repository->startForCustomer($customer, true);
        $second = $repository->startForCustomer($customer, false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\CustomerOnboarding::where('customer_id', $customer->user_id)->count());
    }

    public function test_attach_business_links_the_onboarding_row(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $repository = app(CustomerOnboardingRepository::class);

        $onboarding = $repository->startForCustomer($customer, true);
        $repository->attachBusiness($onboarding, $business);

        $this->assertSame($business->id, $onboarding->fresh()->business_id);
    }

    public function test_mark_step_complete_records_unique_steps_and_advances(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);

        $repository->markStepComplete($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        $repository->markStepComplete($onboarding, OnboardingStep::Goals, OnboardingStep::Business);

        $onboarding->refresh();

        $this->assertSame(['goals'], $onboarding->completed_steps);
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);
    }

    public function test_complete_analysis_ignores_a_stale_version(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);

        $repository->startAnalysis($onboarding, 1);
        $repository->startAnalysis($onboarding->fresh(), 2);

        $stale = $repository->completeAnalysis($onboarding->fresh(), 1, ['profile_completeness_percent' => 40]);

        $this->assertNull($stale->analysis_payload);
        $this->assertSame(OnboardingStatus::AnalysisPending, $stale->status);
    }

    public function test_complete_analysis_applies_the_current_version(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);

        $repository->startAnalysis($onboarding, 1);
        $result = $repository->completeAnalysis($onboarding->fresh(), 1, ['profile_completeness_percent' => 40]);

        $this->assertSame(OnboardingStatus::ResultsReady, $result->status);
        $this->assertSame(OnboardingStep::Results, $result->current_step);
        $this->assertSame(40, $result->analysis_payload['profile_completeness_percent']);
    }

    public function test_fail_analysis_stores_a_safe_error_and_keeps_step_at_analysis(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);

        $repository->startAnalysis($onboarding, 1);
        $result = $repository->failAnalysis($onboarding->fresh(), 1, 'We could not finish the analysis. Please retry.');

        $this->assertSame(OnboardingStatus::Failed, $result->status);
        $this->assertSame(OnboardingStep::Analysis, $result->current_step);
        $this->assertSame('We could not finish the analysis. Please retry.', $result->analysis_error);
    }

    public function test_complete_and_dismiss_set_their_respective_timestamps(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);

        $completed = $repository->complete($onboarding);
        $this->assertSame(OnboardingStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $otherCustomer = $this->createCustomer();
        $dismissible = $repository->startForCustomer($otherCustomer, true);
        $dismissed = $repository->dismiss($dismissible);
        $this->assertSame(OnboardingStatus::Dismissed, $dismissed->status);
        $this->assertNotNull($dismissed->dismissed_at);
    }
}
