<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Events\Business\BusinessCreated;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Entitlement\WorkspacePlanChanged;
use App\Jobs\NicheBlueprint\InstallNicheBlueprintForBusiness;
use App\Library\Business\BusinessManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\BlueprintInstallationRunResult;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Listeners\NicheBlueprint\InstallBlueprintOnBusinessCreated;
use App\Listeners\NicheBlueprint\InstallBlueprintOnFirstPlanAssigned;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CrmPipeline;
use App\Models\Customer;
use App\Models\Workspace;
use App\Providers\EventServiceProvider;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use ReflectionFunction;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\TestCase;

/**
 * Contract 20 §9.1/§8.1/§8.2/§13.5, Sub-slice C — THE TRIGGER MATRIX.
 *
 * §13.5's five cases A–E, driven by the real, registered events rather than by
 * calling the installer directly. `NicheBlueprintInstallerTest` already proves
 * what the engine decides; this file proves WHEN it is allowed to run at all,
 * which is the half of the contract Addendum §16 is actually about:
 *
 * > a plan upgrade must NEVER silently install anything into an established
 * > Business.
 *
 * WHY THESE TESTS CREATE THE BUSINESS THROUGH `BusinessManager` RATHER THAN
 * THROUGH THE FIXTURE TRAIT'S `addBusiness()`. `addBusiness()` writes straight
 * through `BusinessRepository::createForCustomerInWorkspace()`, which persists
 * the row and dispatches NOTHING — it is a fixture seam, and a trigger test
 * built on it would be asserting against an event the test itself invented.
 * `BusinessManager::createBusinessForNewWorkspace()` is the real Contract 07
 * Agency-provisioning write, and it is the production code — not this test —
 * that dispatches `BusinessCreated` after commit. Every case below therefore
 * begins from a genuine creation seam, and the Business is in the same
 * `Draft` status at trigger time that production gives it.
 *
 * The queue connection is `sync` under `phpunit.xml`, so the dispatched
 * `InstallNicheBlueprintForBusiness` job runs inline: what these tests observe
 * afterwards is the real listener → real job → real installer chain.
 */
class BlueprintInstallationTriggerMatrixTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerBlueprintTestAdapters();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    // =====================================================================
    // Case A — plan assignment exists BEFORE BusinessCreated fires.
    // =====================================================================

    public function test_case_a_a_plan_assigned_before_the_business_installs_exactly_once(): void
    {
        $this->publishTriggerMatrixBlueprint();

        // The canonical signup ordering (Blueprint §6): plan first. The
        // WorkspacePlanAssigned this dispatches is a genuine no-op — there is
        // no Business yet — so the ONLY thing that can install below is
        // BusinessCreated.
        [$customer, $workspace] = $this->workspaceWithPlanButNoBusiness(WorkspacePlanTier::Growth);

        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->count());

        $business = $this->provisionBusiness($customer, $workspace, 'Case A Studios');

        // Every Available-and-entitled component installed; the Planned one
        // did not, which is what makes "everything entitled" a real claim
        // rather than "everything".
        $this->assertRecordStates($business, [
            'everywhere' => BlueprintComponentInstallationState::Installed,
            'growth_only' => BlueprintComponentInstallationState::Installed,
            'planned' => BlueprintComponentInstallationState::SkippedUnavailable,
        ]);

        // EXACTLY ONE record per component — the whole point of "installs
        // exactly once". A second run would have to either duplicate a row
        // (the UNIQUE key forbids it) or rewrite one.
        $this->assertSame(1, $this->recordCountFor($business, 'everywhere'));
        $this->assertSame(1, $this->recordCountFor($business, 'growth_only'));
        $this->assertSame(1, $this->recordCountFor($business, 'planned'));
        $this->assertSame(3, $this->installationRecordCount($business));

        // EXACTLY ONE Business-owned row per INSTALLED component, and none at
        // all for the skipped one. The adapter writes unconditionally, so a
        // second install would have produced a second pipeline row.
        $this->assertSame(['everywhere', 'growth_only'], $this->businessOwnedRowNames($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));

        // The provenance really points at the rows that exist.
        $records = $this->installationRecords($business);
        $pipelineIds = CrmPipeline::query()->where('business_id', $business->id)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        foreach (['everywhere', 'growth_only'] as $key) {
            $this->assertSame('crm_pipeline', $records[$key]->installed_record_type);
            $this->assertContains((int) $records[$key]->installed_record_id, $pipelineIds);
            $this->assertNotNull($records[$key]->installed_at);
        }

        $this->assertNull($records['planned']->installed_record_id);
    }

    // =====================================================================
    // Case B — Business created with NO plan assignment at all.
    // =====================================================================

    public function test_case_b_a_business_created_with_no_plan_assignment_writes_no_records_at_all(): void
    {
        $this->publishTriggerMatrixBlueprint();

        // Agency client provisioning (Contract 07): Workspace and Business
        // exist before any plan is assigned.
        [$customer, $workspace] = $this->workspaceWithoutPlanOrBusiness('Case B');

        $business = $this->provisionBusiness($customer, $workspace, 'Case B Studios');

        // ZERO records — INCLUDING zero skip rows. This is the assertion the
        // contract cares about most in this case: §7.2 never revisits a skip,
        // so a `skipped_unentitled` row written here because the plan landed a
        // moment late would permanently deny this Business its own Blueprint.
        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertCount(0, $this->installationRecords($business));
        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->count());
        $this->assertSame(0, $this->businessOwnedRowCount($business));

        // ... and zero for the RIGHT reason. Without this, the case would pass
        // just as happily if the Blueprint had failed to resolve at all.
        $result = app(NicheBlueprintInstaller::class)->installForBusiness($business->fresh());

        $this->assertSame(BlueprintInstallationRunResult::ABORT_WORKSPACE_PLAN_UNASSIGNED, $result->abortReason);
        $this->assertSame(0, $result->decided());
        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->count());
    }

    // =====================================================================
    // Case C — the first WorkspacePlanAssigned then installs, automatically.
    // =====================================================================

    public function test_case_c_the_first_plan_assignment_installs_automatically_with_no_operator_action(): void
    {
        $this->publishTriggerMatrixBlueprint();

        // --- Case B's state, reached exactly the same way. ---
        [$customer, $workspace] = $this->workspaceWithoutPlanOrBusiness('Case C');
        $business = $this->provisionBusiness($customer, $workspace, 'Case C Studios');

        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));

        // --- The ONLY thing that happens next: a first plan is assigned. ---
        // No artisan command, no blueprint:install-missing, no operator.
        $this->assignTier($workspace, WorkspacePlanTier::Growth);

        $this->assertRecordStates($business, [
            'everywhere' => BlueprintComponentInstallationState::Installed,
            'growth_only' => BlueprintComponentInstallationState::Installed,
            'planned' => BlueprintComponentInstallationState::SkippedUnavailable,
        ]);

        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(['everywhere', 'growth_only'], $this->businessOwnedRowNames($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // Case D — BOTH triggers fire for one account.
    // =====================================================================

    public function test_case_d_both_triggers_for_one_account_still_install_exactly_once(): void
    {
        $this->publishTriggerMatrixBlueprint();

        [$customer, $workspace] = $this->workspaceWithPlanButNoBusiness(WorkspacePlanTier::Growth);
        $business = $this->provisionBusiness($customer, $workspace, 'Case D Studios');

        $recordsBefore = $this->installationSnapshot($business);
        $pipelinesBefore = $this->businessOwnedSnapshot($business);

        $this->assertCount(3, $recordsBefore);
        $this->assertCount(2, $pipelinesBefore);

        // Move the clock so that ANY rewrite of a record — even one that
        // changed no other column — would show up as a different updated_at.
        $this->travel(7)->minutes();

        $this->assertTrue(
            Carbon::parse($recordsBefore[0]['updated_at'])->lt(now()),
            'The clock must have genuinely advanced, or the updated_at proof below would be vacuous.',
        );

        // The second trigger, for real: the same Workspace's plan-assignment
        // event redelivered, the creation event redelivered, and the job
        // itself dispatched again — every route into the engine that exists.
        WorkspacePlanAssigned::dispatch(
            (int) $workspace->id,
            $this->planCatalogIdFor($workspace),
            $this->platformAdminId(),
        );

        BusinessCreated::dispatch((int) $business->id, (int) $customer->user_id);

        InstallNicheBlueprintForBusiness::dispatch((int) $business->id);

        $recordsAfter = $this->installationSnapshot($business);
        $pipelinesAfter = $this->businessOwnedSnapshot($business);

        // Still exactly one record per component and one Business-owned row
        // per installed component ...
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(1, $this->recordCountFor($business, 'everywhere'));
        $this->assertSame(1, $this->recordCountFor($business, 'growth_only'));
        $this->assertSame(1, $this->recordCountFor($business, 'planned'));
        $this->assertSame(2, $this->businessOwnedRowCount($business));

        // ... and the second invocation was a PROVEN no-op: identical ids,
        // identical updated_at, identical every column.
        $this->assertSame($recordsBefore, $recordsAfter);
        $this->assertSame($pipelinesBefore, $pipelinesAfter);
    }

    // =====================================================================
    // Case E — WorkspacePlanChanged on an ESTABLISHED Business.
    // =====================================================================

    public function test_case_e_an_upgrade_on_an_established_business_installs_nothing(): void
    {
        $this->publishTriggerMatrixBlueprint();

        // An established Core Business: one component installed, one skipped
        // for want of entitlement, one skipped for want of availability.
        [$customer, $workspace] = $this->workspaceWithPlanButNoBusiness(WorkspacePlanTier::Core);
        $business = $this->provisionBusiness($customer, $workspace, 'Case E Studios');

        $this->assertRecordStates($business, [
            'everywhere' => BlueprintComponentInstallationState::Installed,
            'growth_only' => BlueprintComponentInstallationState::SkippedUnentitled,
            'planned' => BlueprintComponentInstallationState::SkippedUnavailable,
        ]);
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));

        $entitlements = app(EntitlementManager::class);
        $ownerUserId = (int) $workspace->owner_user_id;

        // The component really is unentitled on Core right now ...
        $this->assertFalse(
            $entitlements->decide($workspace->fresh(), $business->fresh(), self::FEATURE_GROWTH_ONLY, $ownerUserId)->allowed
        );

        $recordsBefore = $this->installationSnapshot($business);
        $pipelinesBefore = $this->businessOwnedSnapshot($business);
        $recordsEverywhereBefore = BusinessBlueprintComponentInstallation::query()->count();
        $pipelinesEverywhereBefore = CrmPipeline::query()->count();

        $this->travel(7)->minutes();

        // --- THE UPGRADE. A real WorkspacePlanChanged, from real production
        // code, on a real established account. ---
        app(EntitlementManager::class)->changePlan(
            $workspace->fresh(),
            WorkspacePlanTier::Growth,
            $this->platformAdminId(),
            'upgrade',
        );

        // ... and after it, the component IS entitled. This is what stops the
        // rest of this test being vacuous: the upgrade genuinely moved the
        // entitlement, and the Blueprint system still did nothing.
        $this->assertTrue(
            $entitlements->decide($workspace->fresh(), $business->fresh(), self::FEATURE_GROWTH_ONLY, $ownerUserId)->allowed
        );

        // ZERO automatic installs. ZERO new installation records. ZERO new
        // Business-owned rows. Not "few" — the full before/after snapshot is
        // byte-identical, so nothing was rewritten either.
        $this->assertSame($recordsBefore, $this->installationSnapshot($business));
        $this->assertSame($pipelinesBefore, $this->businessOwnedSnapshot($business));
        $this->assertSame($recordsEverywhereBefore, BusinessBlueprintComponentInstallation::query()->count());
        $this->assertSame($pipelinesEverywhereBefore, CrmPipeline::query()->count());
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));

        // The previously skipped record is STILL a skip, unmodified — §8.1's
        // "surface, never install", and §8.2's "the record is not rewritten".
        $growthOnly = $this->installationRecords($business)['growth_only'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $growthOnly->state);
        $this->assertSame('not_entitled_by_plan', $growthOnly->decision_reason);
        $this->assertNull($growthOnly->installed_record_type);
        $this->assertNull($growthOnly->installed_record_id);
        $this->assertNull($growthOnly->installed_at);
    }

    public function test_case_e_a_plan_assignment_for_a_workspace_holding_no_business_is_a_no_op(): void
    {
        $this->publishTriggerMatrixBlueprint();

        // workspaceWithPlanButNoBusiness() itself dispatches the first
        // WorkspacePlanAssigned with no Business in the Workspace ...
        [, $workspace] = $this->workspaceWithPlanButNoBusiness(WorkspacePlanTier::Growth);

        // ... and a redelivery of that same event must be equally inert. No
        // exception, and nothing written anywhere in the schema.
        WorkspacePlanAssigned::dispatch(
            (int) $workspace->id,
            $this->planCatalogIdFor($workspace),
            $this->platformAdminId(),
        );

        $this->assertSame(0, Business::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->count());
        $this->assertSame(0, CrmPipeline::query()->count());
    }

    // =====================================================================
    // The structural twin of case E — the listener list itself.
    // =====================================================================

    /**
     * Case E proves the upgrade installed nothing on one account. This proves
     * WHY, and proves it for every account at once: there is no niche-Blueprint
     * listener on `WorkspacePlanChanged` to install anything, and the two
     * listeners that do exist are on exactly the two §9.1 trigger events.
     */
    public function test_workspace_plan_changed_has_no_niche_blueprint_listener_while_both_triggers_do(): void
    {
        // --- 1. The live dispatcher, as the application actually booted it. ---
        $onBusinessCreated = $this->registeredListenerDescriptors(BusinessCreated::class);
        $onPlanAssigned = $this->registeredListenerDescriptors(WorkspacePlanAssigned::class);
        $onPlanChanged = $this->registeredListenerDescriptors(WorkspacePlanChanged::class);

        // The extraction below has to be able to SEE a listener, or every
        // negative assertion in this test would be worthless.
        $this->assertContains(InstallBlueprintOnBusinessCreated::class, $onBusinessCreated);
        $this->assertContains(InstallBlueprintOnFirstPlanAssigned::class, $onPlanAssigned);

        $this->assertNotContains(InstallBlueprintOnBusinessCreated::class, $onPlanChanged);
        $this->assertNotContains(InstallBlueprintOnFirstPlanAssigned::class, $onPlanChanged);

        foreach ($onPlanChanged as $descriptor) {
            $this->assertStringNotContainsString(
                'NicheBlueprint',
                $descriptor,
                'WorkspacePlanChanged must never reach the niche Blueprint domain: found ' . $descriptor,
            );
        }

        // --- 2. The declared mapping, so the omission is auditable in source
        //        and not merely an accident of boot order. ---
        $listen = (new ReflectionClass(EventServiceProvider::class))->getDefaultProperties()['listen'];

        $this->assertContains(InstallBlueprintOnBusinessCreated::class, $listen[BusinessCreated::class]);
        $this->assertContains(InstallBlueprintOnFirstPlanAssigned::class, $listen[WorkspacePlanAssigned::class]);

        // The two Blueprint listeners are mapped to those two events and to
        // NOTHING else — including, but not limited to, WorkspacePlanChanged.
        $blueprintTriggerEvents = [];

        foreach ($listen as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                if (is_string($listener) && str_starts_with($listener, 'App\\Listeners\\NicheBlueprint\\')) {
                    $blueprintTriggerEvents[$event] = true;
                }
            }
        }

        $this->assertSame(
            [BusinessCreated::class, WorkspacePlanAssigned::class],
            array_keys($blueprintTriggerEvents),
        );
    }

    // =====================================================================
    // Fixtures and assertions shared by the matrix
    // =====================================================================

    /**
     * The three-way discriminator, published BEFORE any trigger fires — a
     * Blueprint published afterwards would have nothing to install into.
     */
    private function publishTriggerMatrixBlueprint(): void
    {
        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);
    }

    /**
     * A Workspace with neither a plan assignment nor a Business — the starting
     * point for the Business-first ordering (cases B and C). The fixture
     * trait's businessWithoutPlan() creates the Business through the repository
     * seam, which dispatches nothing; these cases need the real event, so the
     * Business is added separately by provisionBusiness().
     *
     * @return array{0: Customer, 1: Workspace}
     */
    private function workspaceWithoutPlanOrBusiness(string $workspaceName): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $workspaceName]);

        return [$customer, $workspace->fresh()];
    }

    /**
     * The real Contract 07 provisioning write. PRODUCTION code dispatches
     * `BusinessCreated` from inside it, after commit — this test only asks for
     * a Business.
     */
    private function provisionBusiness(Customer $customer, Workspace $workspace, string $name): Business
    {
        return app(BusinessManager::class)
            ->createBusinessForNewWorkspace($customer, $workspace, $this->businessAttributes(['name' => $name]))
            ->fresh();
    }

    private function planCatalogIdFor(Workspace $workspace): int
    {
        return (int) DB::table('workspace_plan_assignments')
            ->where('workspace_id', $workspace->id)
            ->value('workspace_plan_catalog_id');
    }

    /** @param  array<string, BlueprintComponentInstallationState>  $expected */
    private function assertRecordStates(Business $business, array $expected): void
    {
        $records = $this->installationRecords($business);

        $this->assertSame(array_keys($expected), $records->keys()->all());

        foreach ($expected as $componentKey => $state) {
            $this->assertSame(
                $state,
                $records[$componentKey]->state,
                "Component [{$componentKey}] is in the wrong installation state.",
            );
        }
    }

    private function recordCountFor(Business $business, string $componentKey): int
    {
        return BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_key', $componentKey)
            ->count();
    }

    /** @return list<string> the names of the Business-owned rows a successful install created */
    private function businessOwnedRowNames(Business $business): array
    {
        return CrmPipeline::query()
            ->where('business_id', $business->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Raw rows, every column, so a before/after comparison catches a rewrite
     * that changed nothing but `updated_at`.
     *
     * @return list<array<string, mixed>>
     */
    private function installationSnapshot(Business $business): array
    {
        return DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)
            ->orderBy('component_key')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function businessOwnedSnapshot(Business $business): array
    {
        return DB::table('crm_pipelines')
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    /**
     * What is REGISTERED on the live dispatcher for an event, as class names.
     *
     * `Event::getListeners()` hands back the dispatcher's wrapping closures, so
     * the class each one delegates to is recovered from the closure's own bound
     * state. Anything unrecognisable is described rather than dropped — a
     * silently discarded listener would turn this file's negative assertions
     * into decoration.
     *
     * @return list<string>
     */
    private function registeredListenerDescriptors(string $eventClass): array
    {
        return array_map(
            fn ($listener) => $this->describeListener($listener),
            array_values(Event::getListeners($eventClass)),
        );
    }

    private function describeListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if (is_array($listener) && count($listener) === 2) {
            $target = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];

            return $target . '@' . (string) $listener[1];
        }

        if ($listener instanceof Closure) {
            $reflection = new ReflectionFunction($listener);
            $inner = $reflection->getStaticVariables()['listener'] ?? null;

            if ($inner !== null && ! $inner instanceof Closure) {
                return $this->describeListener($inner);
            }

            return 'closure@' . ($reflection->getFileName() ?: 'unknown') . ':' . $reflection->getStartLine();
        }

        if (is_object($listener)) {
            return $listener::class;
        }

        return get_debug_type($listener);
    }
}
