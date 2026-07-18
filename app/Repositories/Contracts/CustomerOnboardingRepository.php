<?php

namespace App\Repositories\Contracts;

use App\Enums\Business\OnboardingStep;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;

/**
 * customer_onboardings.customer_id stores users.id (Ultimate SMS's established tenant
 * convention), not customers.id. Methods below resolve it via Customer::$user_id.
 */
interface CustomerOnboardingRepository extends BaseRepository
{
    public function findByCustomer(Customer $customer): ?CustomerOnboarding;

    public function startForCustomer(Customer $customer, bool $required): CustomerOnboarding;

    public function attachBusiness(CustomerOnboarding $onboarding, Business $business): CustomerOnboarding;

    /**
     * @param  array<int, string>  $goals  BusinessGoal values, max two (RFC-001 §17).
     */
    public function updatePrimaryGoals(CustomerOnboarding $onboarding, array $goals): CustomerOnboarding;

    public function markStepComplete(CustomerOnboarding $onboarding, OnboardingStep $step, OnboardingStep $nextStep): CustomerOnboarding;

    public function startAnalysis(CustomerOnboarding $onboarding, int $version): CustomerOnboarding;

    public function completeAnalysis(CustomerOnboarding $onboarding, int $version, array $payload): CustomerOnboarding;

    public function failAnalysis(CustomerOnboarding $onboarding, int $version, string $safeError): CustomerOnboarding;

    public function recordFirstValueAction(CustomerOnboarding $onboarding, string $actionKey): CustomerOnboarding;

    public function complete(CustomerOnboarding $onboarding): CustomerOnboarding;

    public function dismiss(CustomerOnboarding $onboarding): CustomerOnboarding;
}
