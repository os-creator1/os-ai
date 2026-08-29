<?php

namespace Tests\Feature\Usage;

use Tests\TestCase;

/**
 * RFC-005 Admin Usage Billing Surface Contract §5.3 — static source-
 * boundary tests, mirroring EntitlementCatalogSourceBoundaryTest's own
 * established grep-the-source-text technique. Enforces §3's exclusions
 * mechanically, not merely by convention.
 */
class AdminUsageBillingSurfaceBoundaryTest extends TestCase
{
    /**
     * Every path on this contract's own production allow-list (§6),
     * exactly 30. The five Eloquent repository implementations are
     * expected/authorized to contain raw business_usage_ (wildcard) or
     * platform_feature_usage_safety_limits table references.
     *
     * @return array<int, string>
     */
    private function productionAllowListPaths(): array
    {
        return [
            'app/Http/Controllers/Admin/UsageBillingController.php',
            'app/Http/Requests/Admin/IssueManualWalletCreditRequest.php',
            'app/Http/Requests/Admin/SuspendBusinessWalletBillingRequest.php',
            'app/Http/Requests/Admin/ResumeBusinessWalletBillingRequest.php',
            'app/Http/Requests/Admin/RetryFundingAttemptAsAdministratorRequest.php',
            'app/Http/Requests/Admin/SetPlatformFeatureUsageSafetyLimitRequest.php',
            'app/Library/Usage/UsageWalletManager.php',
            'app/Library/Usage/UsageBillingCheckoutManager.php',
            'app/Exceptions/Usage/InvalidAdminCreditEntryTypeException.php',
            'app/Exceptions/Usage/InvalidAdminCreditAmountException.php',
            'app/Exceptions/Usage/InvalidAdminCreditReasonException.php',
            'app/Exceptions/Usage/ManualCreditOperationConflictException.php',
            'app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Contracts/BusinessFundingAttemptRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php',
            'app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php',
            'app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php',
            'app/Repositories/Contracts/BusinessUsageWalletBillingStatusTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php',
            'app/Repositories/Contracts/BusinessUsageLimitTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php',
            'app/Models/BusinessFundingAttemptTransition.php',
            'database/migrations/2026_08_28_120001_add_reason_to_business_funding_attempt_transitions_table.php',
            'routes/admin.php',
            'resources/views/admin/usage-billing/businesses/show.blade.php',
            'resources/views/admin/usage-billing/safety-limits/index.blade.php',
            'resources/views/admin/businesses/show.blade.php',
            'app/Helpers/Helper.php',
            'app/Exceptions/Usage/InvalidAdminCreditOperationIdException.php',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function exemptEloquentRepositoryFiles(): array
    {
        return [
            'app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php',
            'app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php',
        ];
    }

    public function test_the_admin_usage_billing_controller_never_calls_a_charge_originating_manager_method(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Admin/UsageBillingController.php'));

        foreach (['initiateTopUp', 'initiateAutoRecharge', 'initiateAddonPurchase', 'quoteAdditionalSlotAgreement'] as $method) {
            $this->assertStringNotContainsString($method, $contents, "UsageBillingController.php must never call {$method}().");
        }
    }

    public function test_the_admin_usage_billing_controller_never_calls_configure_auto_recharge(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Admin/UsageBillingController.php'));

        $this->assertStringNotContainsString('configureAutoRecharge', $contents);
    }

    public function test_issue_manual_credit_never_calls_credit_from_funding(): void
    {
        $contents = file_get_contents(base_path('app/Library/Usage/UsageWalletManager.php'));

        $signaturePos = strpos($contents, 'public function issueManualCredit(');
        $this->assertNotFalse($signaturePos, 'issueManualCredit() must exist on UsageWalletManager.');

        $afterSignature = substr($contents, $signaturePos + strlen('public function issueManualCredit('));
        $nextMethodMatched = preg_match('/\n    (?:public|private|protected) function /', $afterSignature, $matches, PREG_OFFSET_CAPTURE);
        $body = $nextMethodMatched ? substr($afterSignature, 0, $matches[0][1]) : $afterSignature;

        $this->assertStringNotContainsString('creditFromFunding', $body, "issueManualCredit()'s own body must never call creditFromFunding().");
    }

    public function test_no_admin_usage_billing_production_file_contains_a_raw_billing_table_query(): void
    {
        $exempt = $this->exemptEloquentRepositoryFiles();

        foreach ($this->productionAllowListPaths() as $path) {
            if (in_array($path, $exempt, true)) {
                continue;
            }

            if (! file_exists(base_path($path))) {
                continue; // non-PHP/blade paths without raw-query risk are still scanned below if present
            }

            $contents = file_get_contents(base_path($path));

            $this->assertDoesNotMatchRegularExpression(
                "/DB::table\\('business_usage_|DB::table\\('platform_feature_usage_safety_limits/",
                $contents,
                "{$path} must never query a Usage billing table directly — only the five Eloquent repository implementations may."
            );
        }
    }

    public function test_the_admin_usage_billing_controller_never_references_payment_instrument_manager(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Admin/UsageBillingController.php'));

        $this->assertStringNotContainsString('PaymentInstrumentManager', $contents);
    }
}
