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

    /**
     * Equivalent to findByCustomer(), but scoped by the raw customer_id
     * (users.id) directly, for callers that don't already have a Customer
     * model materialized. Relies on the same database-level uniqueness on
     * customer_onboardings.customer_id, so at most one row is ever returned.
     */
    public function findByCustomerId(int $customerId): ?CustomerOnboarding;

    /**
     * customer_onboardings.business_id carries no unique database
     * constraint (only customer_id does), so if more than one row is ever
     * schema-permitted to reference the same Business, this deterministically
     * returns the most recently created one — never an unordered first().
     */
    public function findByBusiness(Business $business): ?CustomerOnboarding;

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
