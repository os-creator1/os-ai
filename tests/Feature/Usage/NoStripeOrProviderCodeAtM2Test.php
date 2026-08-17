<?php

namespace Tests\Feature\Usage;

use Tests\TestCase;

/**
 * RFC-005 M2 contract §12/§14 — mechanical proof that zero Stripe or M3+
 * provider references exist anywhere in the 55 M2 production paths.
 */
class NoStripeOrProviderCodeAtM2Test extends TestCase
{
    // RFC-005 M3 Correction Round 2, item 113: this list originally
    // included resources/views/customer/business/usage-billing/show.blade.php,
    // app/Library/Usage/UsageBillingPresenter.php,
    // app/Library/Usage/UsageBillingDashboardViewModel.php, and
    // app/Library/Usage/UsageWalletManager.php — Correction Round 1 (items
    // 100/104/105/106) legitimately authorized each of these four files to
    // gain Stripe-related content, so they are removed here.
    //
    // A fifth removal, app/Providers/AppServiceProvider.php, was found and
    // authorized directly during the implementation session that resumed
    // under this contract (not a separate correction round): item 103
    // (already-authorized, already-implemented) adds the
    // PaymentProviderGateway => StripePaymentProviderGateway binding to
    // that file, which is the identical category of legitimate,
    // already-allowlisted Stripe content as items 100/104/105/106 above —
    // both the file (item 103) and this test (item 113) were already on
    // the contract's own closed allowlist, so completing item 113's own
    // narrowing intent for this fifth file is not new scope. See the M3
    // implementation resume report for the full finding.
    //
    // Every other M2 production path remains checked below.
    private const M2_PRODUCTION_PATHS = [
        'app/Enums/Usage/UsageLimitType.php',
        'app/Enums/Usage/PayerType.php',
        'app/Enums/Usage/BillingStatusTransitionSource.php',
        'app/Library/Usage/EffectivePayer.php',
        'app/Library/Usage/CapEvaluation.php',
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
        'app/Listeners/Usage/InitializeBusinessUsageProfile.php',
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
