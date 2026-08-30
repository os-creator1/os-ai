<?php

namespace Tests\Feature\Usage;

use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §17/§22 — static source-boundary tests, mirroring
 * AdminUsageBillingSurfaceBoundaryTest's/EntitlementCatalogSourceBoundaryTest's
 * own established grep-the-source-text technique. Enforces §17's
 * exclusions mechanically, not merely by convention.
 */
class ProviderRefundDisputeSurfaceBoundaryTest extends TestCase
{
    /**
     * §22's own exact 31-path production allow-list.
     *
     * @return array<int, string>
     */
    private function productionAllowListPaths(): array
    {
        return [
            'database/migrations/2026_08_29_120001_add_provider_references_to_business_funding_attempts_table.php',
            'database/migrations/2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php',
            'database/migrations/2026_08_29_120003_add_dispute_refund_aggregate_index_to_business_usage_ledger_entries_table.php',
            'database/migrations/2026_08_29_120004_add_normalized_outcome_columns_to_payment_provider_events_table.php',
            'database/migrations/2026_08_29_120005_add_retry_and_recent_outcomes_indexes_to_payment_provider_events_table.php',
            'database/migrations/2026_08_29_120006_add_refundable_paid_available_micro_to_business_usage_wallets_table.php',
            'database/migrations/2026_08_29_120007_add_paid_attributable_amount_micro_to_business_usage_reservations_table.php',
            'database/migrations/2026_08_29_120008_add_refundable_paid_delta_micro_to_business_usage_ledger_entries_table.php',
            'app/Jobs/Usage/RetryStuckPaymentProviderEvents.php',
            'app/Jobs/Usage/SendChargebackDisputeNotification.php',
            'app/Notifications/Usage/ChargebackDisputeNotification.php',
            'app/Library/Usage/ProviderOutcomeResult.php',
            'app/Library/Usage/PaymentProviderEventRetryPolicy.php',
            'app/Enums/Usage/BillingStatusTransitionSource.php',
            'app/Models/BusinessFundingAttempt.php',
            'app/Models/PaymentProviderEvent.php',
            'app/Models/BusinessUsageWallet.php',
            'app/Models/BusinessUsageReservation.php',
            'app/Models/BusinessUsageLedgerEntry.php',
            'app/Repositories/Contracts/BusinessFundingAttemptRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php',
            'app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Contracts/PaymentProviderEventRepository.php',
            'app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php',
            'app/Library/Usage/UsageWalletManager.php',
            'app/Library/Usage/UsageBillingCheckoutManager.php',
            'app/Jobs/Usage/ProcessPaymentProviderEvent.php',
            'app/Console/Kernel.php',
            'app/Http/Controllers/Admin/PaymentProviderEventController.php',
            'resources/views/admin/usage-billing/provider-events/index.blade.php',
        ];
    }

    /**
     * The only two Eloquent repository implementations authorized to
     * contain a raw business_usage_/payment_provider_events table query.
     *
     * @return array<int, string>
     */
    private function exemptEloquentRepositoryFiles(): array
    {
        return [
            'app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php',
            'app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php',
        ];
    }

    private function methodBody(string $contents, string $signature): string
    {
        $signaturePos = strpos($contents, $signature);
        $this->assertNotFalse($signaturePos, "{$signature} must exist.");

        $afterSignature = substr($contents, $signaturePos + strlen($signature));
        $nextMethodMatched = preg_match('/\n    (?:public|private|protected) function /', $afterSignature, $matches, PREG_OFFSET_CAPTURE);

        return $nextMethodMatched ? substr($afterSignature, 0, $matches[0][1]) : $afterSignature;
    }

    public function test_reversal_and_dispute_manager_methods_are_never_called_from_a_controller(): void
    {
        $controllerFiles = glob(base_path('app/Http/Controllers/**/*.php'));

        $forbiddenCalls = [
            'applyProviderRefund', 'applyDisputeWithdrawal', 'reinstateDisputedFunds',
            'applyRefundOutcome', 'applyDisputeChargebackOutcome', 'applyDisputeReinstatementOutcome',
        ];

        foreach ($controllerFiles as $file) {
            $contents = file_get_contents($file);

            foreach ($forbiddenCalls as $method) {
                $this->assertStringNotContainsString($method, $contents, basename($file)." must never call {$method}().");
            }
        }
    }

    public function test_process_payment_provider_event_never_calls_a_charge_originating_manager_method(): void
    {
        $contents = file_get_contents(base_path('app/Jobs/Usage/ProcessPaymentProviderEvent.php'));

        foreach (['initiateTopUp', 'initiateAutoRecharge', 'initiateAddonPurchase', 'quoteAdditionalSlotAgreement'] as $method) {
            $this->assertStringNotContainsString($method, $contents, "ProcessPaymentProviderEvent.php must never call {$method}().");
        }
    }

    public function test_no_new_production_file_contains_a_raw_billing_table_query_outside_the_two_eloquent_repositories(): void
    {
        $exempt = $this->exemptEloquentRepositoryFiles();

        foreach ($this->productionAllowListPaths() as $path) {
            if (in_array($path, $exempt, true)) {
                continue;
            }

            if (! file_exists(base_path($path)) || ! str_ends_with($path, '.php') || str_starts_with($path, 'database/migrations/')) {
                continue;
            }

            $contents = file_get_contents(base_path($path));

            $this->assertDoesNotMatchRegularExpression(
                "/DB::table\\('business_usage_|DB::table\\('payment_provider_events|DB::table\\('business_funding_attempts/",
                $contents,
                "{$path} must never query a refund/dispute billing table directly — only the two allow-listed Eloquent repository implementations may."
            );
        }
    }

    public function test_apply_outcome_orchestration_methods_are_never_called_outside_process_payment_provider_event(): void
    {
        $checkoutManagerContents = file_get_contents(base_path('app/Library/Usage/UsageBillingCheckoutManager.php'));
        $jobContents = file_get_contents(base_path('app/Jobs/Usage/ProcessPaymentProviderEvent.php'));

        foreach (['applyRefundOutcome', 'applyDisputeChargebackOutcome', 'applyDisputeReinstatementOutcome'] as $method) {
            $this->assertSame(1, substr_count($checkoutManagerContents, "function {$method}("), "{$method}() must be defined exactly once on UsageBillingCheckoutManager.");
            $this->assertStringContainsString("->{$method}(", $jobContents, "ProcessPaymentProviderEvent.php must call {$method}().");
        }

        $otherProductionFiles = array_diff($this->productionAllowListPaths(), [
            'app/Library/Usage/UsageBillingCheckoutManager.php',
            'app/Jobs/Usage/ProcessPaymentProviderEvent.php',
        ]);

        foreach ($otherProductionFiles as $path) {
            if (! file_exists(base_path($path)) || ! str_ends_with($path, '.php')) {
                continue;
            }

            $contents = file_get_contents(base_path($path));

            foreach (['applyRefundOutcome', 'applyDisputeChargebackOutcome', 'applyDisputeReinstatementOutcome'] as $method) {
                $this->assertStringNotContainsString("->{$method}(", $contents, "{$path} must never call {$method}().");
            }
        }
    }

    public function test_no_new_admin_controller_action_or_route_is_introduced_beyond_the_widened_provider_events_index(): void
    {
        $controllerContents = file_get_contents(base_path('app/Http/Controllers/Admin/PaymentProviderEventController.php'));

        preg_match_all('/public function (\w+)\(/', $controllerContents, $matches);
        $publicMethods = array_values(array_diff($matches[1], ['__construct']));

        sort($publicMethods);

        $this->assertSame(['dispose', 'index'], $publicMethods, 'PaymentProviderEventController must expose exactly the two pre-existing actions.');

        $routesContents = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringNotContainsString('provider-events/refund', $routesContents);
        $this->assertStringNotContainsString('provider-events/dispute', $routesContents);
    }

    public function test_none_of_the_three_reversal_methods_ever_references_evaluate_business_auto_recharge(): void
    {
        $contents = file_get_contents(base_path('app/Library/Usage/UsageWalletManager.php'));

        foreach ([
            'public function applyProviderRefund(',
            'public function applyDisputeWithdrawal(',
            'public function reinstateDisputedFunds(',
        ] as $signature) {
            $body = $this->methodBody($contents, $signature);
            $this->assertStringNotContainsString('EvaluateBusinessAutoRecharge', $body, "{$signature} must never reference EvaluateBusinessAutoRecharge.");
        }
    }

    public function test_none_of_the_three_reversal_methods_ever_references_send_receipt_notification_or_attach_funding_receipt(): void
    {
        $contents = file_get_contents(base_path('app/Library/Usage/UsageWalletManager.php'));

        foreach ([
            'public function applyProviderRefund(',
            'public function applyDisputeWithdrawal(',
            'public function reinstateDisputedFunds(',
        ] as $signature) {
            $body = $this->methodBody($contents, $signature);
            $this->assertStringNotContainsString('SendReceiptNotification', $body, "{$signature} must never reference SendReceiptNotification.");
            $this->assertStringNotContainsString('ensureFundingReceipt', $body, "{$signature} must never reference ensureFundingReceipt.");
            $this->assertStringNotContainsString('attachFundingReceipt', $body, "{$signature} must never reference attachFundingReceipt.");
        }
    }
}
