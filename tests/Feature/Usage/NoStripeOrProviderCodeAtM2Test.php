<?php

namespace Tests\Feature\Usage;

use Tests\TestCase;

/**
 * RFC-005 M2 contract §12/§14 — mechanical proof that zero Stripe or M3+
 * provider references exist anywhere in the 55 M2 production paths.
 */
class NoStripeOrProviderCodeAtM2Test extends TestCase
{
    private const M2_PRODUCTION_PATHS = [
        'app/Enums/Usage/UsageLimitType.php',
        'app/Enums/Usage/PayerType.php',
        'app/Enums/Usage/BillingStatusTransitionSource.php',
        'app/Library/Usage/EffectivePayer.php',
        'app/Library/Usage/CapEvaluation.php',
        'app/Library/Usage/UsageBillingPresenter.php',
        'app/Library/Usage/UsageBillingDashboardViewModel.php',
        'app/Library/Usage/UsageLedgerEntryPresentationRow.php',
        'app/Models/BusinessFeatureUsageLimit.php',
        'app/Models/PlatformFeatureUsageSafetyLimit.php',
        'app/Models/BusinessUsageLimitTransition.php',
        'app/Models/BusinessUsageWalletBillingStatusTransition.php',
        'app/Models/BusinessBillingContact.php',
        'app/Models/BusinessPayerAssignment.php',
        'app/Models/BusinessPayerTransition.php',
        'app/Repositories/Contracts/BusinessFeatureUsageLimitRepository.php',
        'app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php',
        'app/Repositories/Contracts/BusinessUsageLimitTransitionRepository.php',
        'app/Repositories/Contracts/BusinessUsageWalletBillingStatusTransitionRepository.php',
        'app/Repositories/Contracts/BusinessBillingContactRepository.php',
        'app/Repositories/Contracts/BusinessPayerAssignmentRepository.php',
        'app/Repositories/Contracts/BusinessPayerTransitionRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessFeatureUsageLimitRepository.php',
        'app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessBillingContactRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessPayerAssignmentRepository.php',
        'app/Repositories/Eloquent/EloquentBusinessPayerTransitionRepository.php',
        'app/Library/Usage/BillingProfileManager.php',
        'app/Exceptions/Usage/UnauthorizedPayerAssignmentException.php',
        'app/Exceptions/Usage/UnauthorizedUsageBillingManagementException.php',
        'app/Exceptions/Usage/FeatureLimitExceedsPlatformSafetyLimitException.php',
        'app/Exceptions/Usage/InvalidBillingContactDataException.php',
        'app/Events/Usage/BusinessPayerChanged.php',
        'app/Events/Usage/BusinessBillingContactChanged.php',
        'app/Events/Usage/BusinessWalletBillingStatusChanged.php',
        'app/Http/Controllers/Customer/Business/UsageBillingController.php',
        'app/Http/Requests/Customer/Business/UpdateBusinessPayerRequest.php',
        'app/Http/Requests/Customer/Business/UpdateBusinessBillingContactRequest.php',
        'app/Http/Requests/Customer/Business/UpdateBusinessSpendCapRequest.php',
        'app/Http/Requests/Customer/Business/UpdateBusinessFeatureLimitRequest.php',
        'resources/views/customer/business/usage-billing/show.blade.php',
        'app/Library/Usage/UsageWalletManager.php',
        'app/Listeners/Usage/InitializeBusinessUsageProfile.php',
        'app/Providers/AppServiceProvider.php',
        'resources/views/customer/workspaces/show.blade.php',
    ];

    private const FORBIDDEN_TERMS = [
        'Stripe', 'stripe-php', 'PaymentIntent', 'CheckoutSession', 'SetupIntent',
        'payment_provider_customers', 'business_payment_instruments', 'business_funding_attempts',
        'payment_provider_events', 'additional_business_slot_agreements', 'business_usage_addon',
    ];

    public function test_no_m2_production_path_references_stripe_or_an_m3_plus_concept(): void
    {
        foreach (self::M2_PRODUCTION_PATHS as $path) {
            $fullPath = base_path($path);
            $this->assertFileExists($fullPath, "Expected M2 path missing: {$path}");

            $source = file_get_contents($fullPath);

            foreach (self::FORBIDDEN_TERMS as $term) {
                $this->assertStringNotContainsString($term, $source, "Forbidden term \"{$term}\" found in {$path}.");
            }
        }
    }
}
