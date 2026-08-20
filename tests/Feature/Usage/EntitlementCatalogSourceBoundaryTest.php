<?php

namespace Tests\Feature\Usage;

use Tests\TestCase;

/**
 * M4 contract §16 (Correction Round 1 §3 item 7m) — the actual, narrower
 * M4 source-boundary invariant, scoped exclusively to §25's own
 * M4-authorized production PHP paths: no M4 production file imports or
 * resolves any RFC-004 repository contract directly; no M4 production
 * file performs raw DB::table(...)/query-builder/Eloquent/repository
 * access against workspace_plan_catalog, workspace_plan_assignments, or
 * workspace_entitlement_transitions; UsageBillingCheckoutManager calls
 * only the three specifically-authorized public EntitlementManager
 * seams; no M4 controller/job calls
 * allocateAdditionalBusinessSlotsFromVerifiedPayment() directly (only
 * performVerifiedAllocation()/performVerifiedRenewalChargeAllocation()
 * may); no M4 code calls updateCatalogPricing() anywhere.
 */
class EntitlementCatalogSourceBoundaryTest extends TestCase
{
    private const RFC004_TABLES = ['workspace_plan_catalog', 'workspace_plan_assignments', 'workspace_entitlement_transitions'];

    private const RFC004_REPOSITORY_CLASSES = [
        'WorkspacePlanCatalogRepository', 'EloquentWorkspacePlanCatalogRepository',
        'WorkspacePlanAssignmentRepository', 'EloquentWorkspacePlanAssignmentRepository',
        'WorkspaceEntitlementTransitionRepository', 'EloquentWorkspaceEntitlementTransitionRepository',
    ];

    /**
     * @return array<int, string>
     */
    private function m4ProductionFiles(): array
    {
        $paths = [
            'app/Models/AdditionalBusinessSlotAgreement.php',
            'app/Models/AdditionalBusinessSlotAgreementTransition.php',
            'app/Models/AdditionalBusinessSlotRenewalCharge.php',
            'app/Models/AdditionalBusinessSlotRenewalChargeTransition.php',
            'app/Models/BusinessUsageAddonCatalog.php',
            'app/Models/BusinessUsageAddonPurchase.php',
            'app/Models/BusinessUsageAddonPurchaseTransition.php',
            'app/Repositories/Contracts/AdditionalBusinessSlotAgreementRepository.php',
            'app/Repositories/Contracts/AdditionalBusinessSlotAgreementTransitionRepository.php',
            'app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeRepository.php',
            'app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeTransitionRepository.php',
            'app/Repositories/Contracts/BusinessUsageAddonCatalogRepository.php',
            'app/Repositories/Contracts/BusinessUsageAddonPurchaseRepository.php',
            'app/Repositories/Contracts/BusinessUsageAddonPurchaseTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentAdditionalBusinessSlotAgreementRepository.php',
            'app/Repositories/Eloquent/EloquentAdditionalBusinessSlotAgreementTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentAdditionalBusinessSlotRenewalChargeRepository.php',
            'app/Repositories/Eloquent/EloquentAdditionalBusinessSlotRenewalChargeTransitionRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageAddonCatalogRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageAddonPurchaseRepository.php',
            'app/Repositories/Eloquent/EloquentBusinessUsageAddonPurchaseTransitionRepository.php',
            'app/Library/Usage/UsageBillingCheckoutManager.php',
            'app/Library/Usage/PaymentInstrumentManager.php',
            'app/Jobs/Usage/InitiateSlotAgreementRenewal.php',
            'app/Jobs/Usage/FinalizeSlotAgreementCancellation.php',
            'app/Jobs/Usage/ReconcileSlotAgreementAllocation.php',
            'app/Jobs/Usage/SendSlotAgreementPriceChangeNotice.php',
            'app/Jobs/Usage/ProcessPaymentProviderEvent.php',
            'app/Http/Controllers/Customer/Workspace/AdditionalBusinessSlotAgreementController.php',
            'app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php',
        ];

        return array_values(array_filter($paths, fn ($p) => file_exists(base_path($p))));
    }

    public function test_no_m4_production_file_imports_an_rfc004_repository_contract(): void
    {
        foreach ($this->m4ProductionFiles() as $path) {
            $contents = file_get_contents(base_path($path));

            foreach (self::RFC004_REPOSITORY_CLASSES as $class) {
                $this->assertStringNotContainsString($class, $contents, "{$path} must not reference {$class} directly.");
            }
        }
    }

    public function test_no_m4_production_file_performs_raw_query_access_against_rfc004_tables(): void
    {
        foreach ($this->m4ProductionFiles() as $path) {
            $contents = file_get_contents(base_path($path));

            foreach (self::RFC004_TABLES as $table) {
                $this->assertStringNotContainsString("'{$table}'", $contents, "{$path} must not directly query the RFC-004 table '{$table}'.");
                $this->assertStringNotContainsString("\"{$table}\"", $contents, "{$path} must not directly query the RFC-004 table \"{$table}\".");
            }
        }
    }

    public function test_manager_calls_only_the_three_authorized_entitlement_manager_seams(): void
    {
        $contents = file_get_contents(base_path('app/Library/Usage/UsageBillingCheckoutManager.php'));

        preg_match_all('/entitlementManager->(\w+)\(/', $contents, $matches);
        $calledMethods = array_unique($matches[1]);

        $authorized = ['allocateAdditionalBusinessSlotsFromVerifiedPayment', 'getWorkspaceEntitlementSummary', 'listPlanCatalogSummaries'];

        foreach ($calledMethods as $method) {
            $this->assertContains($method, $authorized, "UsageBillingCheckoutManager calls an unauthorized EntitlementManager method: {$method}().");
        }
    }

    public function test_no_m4_controller_or_job_calls_the_rfc004_allocation_method_directly(): void
    {
        $controllersAndJobs = array_filter($this->m4ProductionFiles(), fn ($p) => str_contains($p, 'Controllers') || str_contains($p, 'Jobs'));

        foreach ($controllersAndJobs as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertStringNotContainsString('allocateAdditionalBusinessSlotsFromVerifiedPayment', $contents, "{$path} must call performVerifiedAllocation()/performVerifiedRenewalChargeAllocation(), never the RFC-004 method directly.");
        }
    }

    public function test_no_m4_code_calls_update_catalog_pricing(): void
    {
        foreach ($this->m4ProductionFiles() as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertStringNotContainsString('updateCatalogPricing', $contents, "{$path} must never call updateCatalogPricing() — catalog writes remain platform-administrator-only through EntitlementManager's own admin surface.");
        }
    }
}
