<?php

namespace Tests\Feature\NicheBlueprint\Support;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CrmPipeline;
use App\Models\Customer;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Contract 20 Sub-slice C — shared fixtures for the installation engine's
 * tests, so the focused, trigger-matrix and concurrency suites all build the
 * same shapes the same way.
 *
 * THE THREE-WAY FEATURE DISCRIMINATOR, which most of §13's proofs depend on,
 * is chosen from real repository state rather than invented:
 *
 * | component feature                | Available? | Core plan | Growth plan | expected outcome                          |
 * |----------------------------------|------------|-----------|-------------|-------------------------------------------|
 * | `crm`                            | Available  | entitled  | entitled    | installed on both tiers                   |
 * | `google_business_profile_module` | Available  | NO        | entitled    | skipped_unentitled on Core, installed on Growth |
 * | `calendar`                       | **Planned**| entitled  | entitled    | skipped_unavailable on BOTH tiers         |
 *
 * `calendar` is the load-bearing one: its plan packaging entitles it at every
 * tier, so the ONLY thing that can produce `skipped_unavailable` for it is the
 * availability floor inside `decide()`. That proves §6.3's reason mapping is
 * genuinely doing the work, rather than coinciding with plan packaging.
 */
trait CreatesBlueprintInstallationFixtures
{
    use CreatesCustomerContextFixtures;

    /** The industry `businessAttributes()` gives every fixture Business. */
    protected const FIXTURE_INDUSTRY = 'photo_booth_service';

    protected const FEATURE_INSTALLS_EVERYWHERE = 'crm';

    protected const FEATURE_GROWTH_ONLY = 'google_business_profile_module';

    protected const FEATURE_PLANNED = 'calendar';

    /**
     * Registers both test-only adapters. Required before publishing, because
     * the publisher's fail-closed gate (§6.2 check 1) refuses a component_type
     * with no registered adapter — and required before installing, because the
     * installer resolves the adapter for an allowed decision.
     */
    protected function registerBlueprintTestAdapters(?TestThrowingComponentAdapter $throwing = null): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        if (! $registry->has(TestInstallingComponentAdapter::TYPE)) {
            $registry->register(new TestInstallingComponentAdapter());
        }

        if (! $registry->has(TestThrowingComponentAdapter::TYPE)) {
            $registry->register($throwing ?? new TestThrowingComponentAdapter());
        }
    }

    protected function blueprintPublisher(): NicheBlueprintPublisher
    {
        return app(NicheBlueprintPublisher::class);
    }

    /**
     * A published Blueprint resolving through §7.1's BROAD-INDUSTRY fallback
     * (`vertical_key IS NULL`), which every fixture Business matches by its
     * `businesses.industry`.
     *
     * @param  list<array{key: string, feature: string, type?: string, payload?: array}>  $components
     */
    protected function publishBlueprint(
        array $components,
        string $key = 'photo_booth',
        ?string $broadIndustry = self::FIXTURE_INDUSTRY,
        ?string $verticalKey = null,
    ): NicheBlueprint {
        $publisher = $this->blueprintPublisher();
        $adminId = $this->platformAdminId();

        $blueprint = $publisher->createBlueprint($adminId, $key, 'Photo Booth', $verticalKey, $broadIndustry);
        $version = $publisher->createDraftVersion($adminId, $blueprint);

        $this->addComponents($version, $components);

        $publisher->publishVersion($adminId, $version);

        return $blueprint->fresh();
    }

    /**
     * Publishes a FURTHER version of an existing Blueprint — §13.4's "a new
     * Blueprint version is published without touching any live Business".
     *
     * @param  list<array{key: string, feature: string, type?: string, payload?: array}>  $components
     */
    protected function publishNextVersion(NicheBlueprint $blueprint, array $components): NicheBlueprintVersion
    {
        $publisher = $this->blueprintPublisher();
        $adminId = $this->platformAdminId();

        $version = $publisher->createDraftVersion($adminId, $blueprint);

        $this->addComponents($version, $components);

        return $publisher->publishVersion($adminId, $version);
    }

    /** @param  list<array{key: string, feature: string, type?: string, payload?: array}>  $components */
    private function addComponents(NicheBlueprintVersion $version, array $components): void
    {
        $publisher = $this->blueprintPublisher();
        $adminId = $this->platformAdminId();

        foreach ($components as $component) {
            $publisher->addDraftComponent(
                $adminId,
                $version,
                $component['key'],
                $component['type'] ?? TestInstallingComponentAdapter::TYPE,
                $component['feature'],
                $component['payload'] ?? ['name' => $component['key']],
            );
        }
    }

    /**
     * A Workspace whose plan was assigned BEFORE it ever held a Business —
     * §13.5's case A ordering, and the ordering the canonical signup flow
     * (Blueprint §6) produces.
     *
     * The `WorkspacePlanAssigned` this dispatches is a genuine no-op for the
     * installer: the Workspace has no Business yet, so §9.1's listener finds
     * no canonical sole Business and returns.
     *
     * @return array{0: Customer, 1: Workspace}
     */
    protected function workspaceWithPlanButNoBusiness(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Blueprint Fixture']);

        $this->assignTier($workspace, $tier);

        return [$customer, $workspace->fresh()];
    }

    /**
     * A Workspace holding a Business with NO plan assignment at all — §13.5's
     * case B, and the shape Agency client provisioning can genuinely produce.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function businessWithoutPlan(string $businessName = 'Unplanned Studios'): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Unplanned']);
        $business = $this->addBusiness($customer, $workspace, $businessName);

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    /** @return Collection<int, BusinessBlueprintComponentInstallation> keyed by component_key */
    protected function installationRecords(Business $business): Collection
    {
        return BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->orderBy('component_key')
            ->get()
            ->keyBy('component_key');
    }

    protected function installationRecordCount(Business $business): int
    {
        return BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count();
    }

    /** The Business-owned rows a successful install creates — the negative proofs' subject. */
    protected function businessOwnedRowCount(Business $business): int
    {
        return CrmPipeline::query()->where('business_id', $business->id)->count();
    }
}
