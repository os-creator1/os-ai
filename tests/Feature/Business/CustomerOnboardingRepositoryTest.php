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

    public function test_find_by_business_returns_the_owning_onboarding(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->startForCustomer($customer, true);
        $repository->attachBusiness($onboarding, $business);

        $found = $repository->findByBusiness($business);

        $this->assertNotNull($found);
        $this->assertSame($onboarding->id, $found->id);
    }

    public function test_find_by_business_does_not_return_another_businesss_onboarding(): void
    {
        $customerA = $this->createCustomer();
        $businessA = app(BusinessRepository::class)->createForCustomer($customerA, $this->businessAttributes());
        $repository = app(CustomerOnboardingRepository::class);
        $onboardingA = $repository->startForCustomer($customerA, true);
        $repository->attachBusiness($onboardingA, $businessA);

        $customerB = $this->createCustomer();
        $businessB = app(BusinessRepository::class)->createForCustomer($customerB, $this->businessAttributes(['name' => 'Second Business']));
        $onboardingB = $repository->startForCustomer($customerB, true);
        $repository->attachBusiness($onboardingB, $businessB);

        $found = $repository->findByBusiness($businessA);

        $this->assertNotNull($found);
        $this->assertSame($onboardingA->id, $found->id);
        $this->assertNotSame($onboardingB->id, $found->id);
    }

    public function test_find_by_business_returns_null_when_none_exists(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $repository = app(CustomerOnboardingRepository::class);

        // No onboarding row has been attached to this business at all.
        $this->assertNull($repository->findByBusiness($business));
    }

    /**
     * customer_onboardings.business_id carries no unique database
     * constraint (only customer_id does) — this directly exercises that
     * schema-permitted case (two rows referencing the same business_id,
     * created outside the normal attachBusiness() flow) and proves
     * findByBusiness() resolves it deterministically (most recently
     * created row, i.e. highest id) rather than an unordered first().
     */
    public function test_find_by_business_is_deterministic_when_multiple_rows_reference_the_same_business(): void
    {
        $customer = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
        $repository = app(CustomerOnboardingRepository::class);

        $otherCustomer = $this->createCustomer();
        $older = $repository->startForCustomer($customer, true);
        $repository->attachBusiness($older, $business);

        $newer = $repository->startForCustomer($otherCustomer, true);
        $repository->attachBusiness($newer, $business);

        $this->assertGreaterThan($older->id, $newer->id);

        $found = $repository->findByBusiness($business);

        $this->assertSame($newer->id, $found->id);
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
