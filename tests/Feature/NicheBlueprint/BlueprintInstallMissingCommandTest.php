<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CrmPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\Feature\NicheBlueprint\Support\TestThrowingComponentAdapter;
use Tests\TestCase;

/**
 * Contract 20 §9.2, Sub-slice C — `blueprint:install-missing`, the operator
 * RECOVERY path, proved to be exactly that and nothing more.
 *
 * The command is a thin wrapper with no algorithm of its own, so these tests
 * deliberately do not re-prove the engine (that is
 * `NicheBlueprintInstallerTest`'s subject). They prove the four things only
 * the command can be asked about:
 *
 *  1. it genuinely RECOVERS the §9.2 cases — a Business with no records at
 *     all, and individual components recorded `failed`;
 *  2. it is IDEMPOTENT: a second sweep rewrites nothing, not one `updated_at`,
 *     and creates no second Business-owned row;
 *  3. it CANNOT REVERSE A SKIP — the load-bearing one. §9.2 is explicit that
 *     it "is NOT a path for anything previously recorded as
 *     `skipped_unentitled` or `skipped_unavailable`", so an upgraded Workspace
 *     swept again installs nothing, by design;
 *  4. its `--business` selector is exact, and an unresolvable one fails
 *     closed rather than silently sweeping everything.
 *
 * Every fixture Business is created BEFORE its Blueprint is published, so
 * §9.1's two automatic triggers never have anything to install and every
 * record asserted below was written by the command under test — which is also
 * the real shape of §9.2's case 2, "a Business that predates its Blueprint
 * being published".
 */
class BlueprintInstallMissingCommandTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        // Adapters are registered per test, never here: the retry proof needs
        // a throwing adapter with a bounded failure count, and the scope proof
        // needs a genuinely untouched registry.
    }

    // =====================================================================
    // §9.2 case 2 — a Business that never had an installation attempt
    // =====================================================================

    public function test_the_sweep_recovers_a_business_that_never_had_an_installation_attempt(): void
    {
        $this->registerBlueprintTestAdapters();

        // Plan and Business both predate the Blueprint's publication, so no
        // trigger ever had anything to install and no entitlement decision
        // was ever recorded — §9.2's second situation exactly.
        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint($this->discriminatorComponents());

        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('Niche Blueprint installation sweep complete.')
            ->expectsOutputToContain('businesses processed=1')
            ->expectsOutputToContain('runs aborted before any write=0')
            ->expectsOutputToContain('components installed=1')
            ->expectsOutputToContain('components skipped (unentitled)=1')
            ->expectsOutputToContain('components skipped (unavailable)=1')
            ->expectsOutputToContain('components failed=0')
            ->assertSuccessful();

        $records = $this->installationRecords($business);

        // The Core subset, decided by `decide()` alone: Available + entitled
        // installs, Available + unentitled skips, Planned skips on the
        // availability floor even though the plan entitles it at every tier.
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['everywhere']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $records['growth_only']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $records['planned']->state);
        $this->assertSame('not_entitled_by_plan', $records['growth_only']->decision_reason);
        $this->assertSame('platform_feature_unavailable', $records['planned']->decision_reason);
        $this->assertSame(3, $this->installationRecordCount($business));

        // The recovery really installed: the one allowed component wrote a
        // Business-owned row, and the two skips wrote none.
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
        $this->assertSame('crm_pipeline', $records['everywhere']->installed_record_type);
        $this->assertNotNull($records['everywhere']->installed_record_id);
        $this->assertNotNull($records['everywhere']->installed_at);
        // §6.4 — a system-initiated install records no actor.
        $this->assertNull($records['everywhere']->installed_by_user_id);
        $this->assertNull($records['growth_only']->installed_record_id);
        $this->assertNull($records['planned']->installed_record_id);
    }

    // =====================================================================
    // §9.2 — "idempotent, safe to run repeatedly"
    // =====================================================================

    public function test_a_second_sweep_rewrites_no_record_and_creates_no_duplicate_row(): void
    {
        $this->registerBlueprintTestAdapters();

        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint($this->discriminatorComponents());

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('components installed=1')
            ->assertSuccessful();

        $recordsBefore = $this->rawInstallationRows($business);
        $rowsBefore = $this->rawBusinessOwnedRows($business);

        $this->assertCount(3, $recordsBefore);
        $this->assertCount(1, $rowsBefore);

        // A later wall-clock moment, so "updated_at unchanged" is a real
        // assertion rather than one second-resolution timestamps would
        // satisfy by accident.
        $this->travel(5)->seconds();

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('businesses processed=1')
            // Not an abort: the run really did reach its per-component loop,
            // so the zeros below are short-circuits, not a skipped run.
            ->expectsOutputToContain('runs aborted before any write=0')
            ->expectsOutputToContain('components installed=0')
            ->expectsOutputToContain('components skipped (unentitled)=0')
            ->expectsOutputToContain('components skipped (unavailable)=0')
            ->expectsOutputToContain('components failed=0')
            ->assertSuccessful();

        // Every count above is zero because every component short-circuited on
        // its existing record; these two comparisons prove the records
        // themselves are byte-identical, ids and updated_at included.
        $this->assertSame($recordsBefore, $this->rawInstallationRows($business));
        $this->assertSame($rowsBefore, $this->rawBusinessOwnedRows($business));
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
    }

    // =====================================================================
    // §9.2 case 3 / §7.4 — components recorded `failed` are retried
    // =====================================================================

    public function test_the_sweep_retries_a_failed_component_and_clears_its_error_code(): void
    {
        // One failure left in the adapter: the first sweep records `failed`,
        // the second — same container, same adapter instance — installs. That
        // is §9.2's third situation end to end.
        $this->registerBlueprintTestAdapters(new TestThrowingComponentAdapter(failuresRemaining: 1));
        TestThrowingComponentAdapter::resetAttempts();

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->publishBlueprint([
            ['key' => 'first', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'middle', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'type' => TestThrowingComponentAdapter::TYPE],
            ['key' => 'last', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain("business_id={$business->id} components failed: 1")
            ->expectsOutputToContain('components installed=2')
            ->expectsOutputToContain('components failed=1')
            ->assertFailed();

        $afterFailure = $this->installationRecords($business);

        $this->assertSame(BlueprintComponentInstallationState::Failed, $afterFailure['middle']->state);
        $this->assertSame('RuntimeException', $afterFailure['middle']->error_code);
        $this->assertNull($afterFailure['middle']->installed_at);
        $this->assertSame(1, TestThrowingComponentAdapter::$installAttempts);
        // The adapter wrote a row before it threw; §7.4's rollback removed it,
        // and the components on both sides of the failure still ran.
        $this->assertSame(['first', 'last'], $this->businessOwnedRowNames($business));

        $middleId = (int) $afterFailure['middle']->id;
        $firstBefore = $afterFailure['first']->getAttributes();
        $lastBefore = $afterFailure['last']->getAttributes();

        $this->travel(5)->seconds();

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('runs aborted before any write=0')
            ->expectsOutputToContain('components installed=1')
            ->expectsOutputToContain('components failed=0')
            ->assertSuccessful();

        $records = $this->installationRecords($business);

        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['middle']->state);
        $this->assertNull($records['middle']->error_code);
        $this->assertNotNull($records['middle']->installed_at);
        $this->assertSame('crm_pipeline', $records['middle']->installed_record_type);
        $this->assertSame($middleId, (int) $records['middle']->id, 'The failed record was rewritten in place, not duplicated.');
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertTrue(
            CrmPipeline::query()
                ->whereKey($records['middle']->installed_record_id)
                ->where('business_id', $business->id)
                ->where('name', 'middle')
                ->exists()
        );

        // ONLY the failed component was retried: exactly one further adapter
        // attempt, and the two already-installed records are untouched.
        $this->assertSame(2, TestThrowingComponentAdapter::$installAttempts);
        $this->assertSame($firstBefore, $records['first']->getAttributes());
        $this->assertSame($lastBefore, $records['last']->getAttributes());
        $this->assertSame(['first', 'last', 'middle'], $this->businessOwnedRowNames($business));
        $this->assertSame(3, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // §9.2 — "It is NOT a path for anything previously recorded as
    // skipped_unentitled or skipped_unavailable"
    // =====================================================================

    public function test_the_sweep_cannot_reverse_a_skipped_unentitled_record_after_an_upgrade(): void
    {
        $this->registerBlueprintTestAdapters();

        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
        ]);

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('components installed=1')
            ->expectsOutputToContain('components skipped (unentitled)=1')
            ->assertSuccessful();

        $skip = $this->installationRecords($business)['growth_only'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $skip->state);

        $skipId = (int) $skip->id;
        $recordsBefore = $this->rawInstallationRows($business);
        $rowsBefore = $this->rawBusinessOwnedRows($business);

        $this->travel(5)->seconds();

        app(EntitlementManager::class)->changePlan(
            $workspace,
            WorkspacePlanTier::Growth,
            $this->platformAdminId(),
            'upgrade',
        );

        $upgraded = $workspace->fresh();

        // The upgrade genuinely reversed the entitlement the skip was based
        // on — without this, "still skipped" would prove nothing at all.
        $this->assertTrue(
            app(EntitlementManager::class)
                ->decide($upgraded, $business->fresh(), self::FEATURE_GROWTH_ONLY, (int) $upgraded->owner_user_id)
                ->allowed
        );

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('businesses processed=1')
            // Not an abort: resolution still found the Blueprint, so the run
            // genuinely reached this component and chose to leave it alone.
            ->expectsOutputToContain('runs aborted before any write=0')
            ->expectsOutputToContain('components installed=0')
            // Not even re-recorded as a skip: the existing decision was never
            // revisited.
            ->expectsOutputToContain('components skipped (unentitled)=0')
            ->assertSuccessful();

        $after = $this->installationRecords($business)['growth_only'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $after->state);
        $this->assertSame($skipId, (int) $after->id);
        $this->assertNull($after->installed_record_id);
        $this->assertNull($after->installed_at);

        // Nothing rewritten, and ZERO new Business-owned rows: the newly
        // entitled component is surfaced by §8.1's future surface, never
        // installed by this command.
        $this->assertSame($recordsBefore, $this->rawInstallationRows($business));
        $this->assertSame($rowsBefore, $this->rawBusinessOwnedRows($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
    }

    public function test_the_sweep_cannot_reverse_a_skipped_unavailable_record(): void
    {
        $this->registerBlueprintTestAdapters();

        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('components skipped (unavailable)=1')
            ->assertSuccessful();

        $skip = $this->installationRecords($business)['planned'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $skip->state);
        $this->assertSame('platform_feature_unavailable', $skip->decision_reason);

        $skipId = (int) $skip->id;
        $recordsBefore = $this->rawInstallationRows($business);
        $rowsBefore = $this->rawBusinessOwnedRows($business);

        $this->travel(5)->seconds();

        app(EntitlementManager::class)->changePlan(
            $workspace,
            WorkspacePlanTier::Growth,
            $this->platformAdminId(),
            'upgrade',
        );

        $upgraded = $workspace->fresh();

        // Availability is a compile-time constant (`PlatformFeatureRegistry`),
        // so the only runtime change a test can make is the plan — and this
        // feature's packaging already entitled it at every tier, which is
        // exactly why its skip was recorded on the availability floor alone.
        // The decision is therefore still a denial, for the same reason, and
        // the sweep below must still leave it alone.
        $decision = app(EntitlementManager::class)
            ->decide($upgraded, $business->fresh(), self::FEATURE_PLANNED, (int) $upgraded->owner_user_id);

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain('runs aborted before any write=0')
            ->expectsOutputToContain('components installed=0')
            ->expectsOutputToContain('components skipped (unavailable)=0')
            ->assertSuccessful();

        $after = $this->installationRecords($business)['planned'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $after->state);
        $this->assertSame($skipId, (int) $after->id);
        $this->assertSame($recordsBefore, $this->rawInstallationRows($business));
        $this->assertSame($rowsBefore, $this->rawBusinessOwnedRows($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
    }

    // =====================================================================
    // §9.2 — "operating over a single Business or all Businesses"
    // =====================================================================

    public function test_the_business_selector_targets_exactly_one_business_by_id_and_by_uid(): void
    {
        $this->registerBlueprintTestAdapters();

        [, $alpha] = $this->tenant(WorkspacePlanTier::Growth, 'Alpha Studios', 'Alpha');

        $beta = $this->createIndependentWorkspaceBusiness(businessName: 'Beta Studios', workspaceName: 'Beta');
        $this->assignTier($beta['workspace'], WorkspacePlanTier::Growth);
        $betaBusiness = $beta['business']->fresh();

        $this->publishBlueprint($this->discriminatorComponents());

        $this->assertSame(0, $this->installationRecordCount($alpha));
        $this->assertSame(0, $this->installationRecordCount($betaBusiness));

        $this->artisan('blueprint:install-missing', ['--business' => (string) $alpha->id])
            ->expectsOutputToContain('businesses processed=1')
            ->expectsOutputToContain('components installed=2')
            ->expectsOutputToContain('components skipped (unavailable)=1')
            ->assertSuccessful();

        $this->assertSame(3, $this->installationRecordCount($alpha));
        $this->assertSame(2, $this->businessOwnedRowCount($alpha));

        // The other Business was not touched at all — not one record, not one
        // row, although the same Blueprint resolves for it.
        $this->assertSame(0, $this->installationRecordCount($betaBusiness));
        $this->assertSame(0, $this->businessOwnedRowCount($betaBusiness));

        $alphaRecords = $this->rawInstallationRows($alpha);
        $alphaRows = $this->rawBusinessOwnedRows($alpha);

        $this->travel(5)->seconds();

        // The uid form selects the OTHER Business, equally exactly. The uid is
        // non-numeric, so this genuinely exercises the command's uid branch
        // rather than its numeric-id one.
        $this->assertFalse(ctype_digit((string) $betaBusiness->uid));

        $this->artisan('blueprint:install-missing', ['--business' => (string) $betaBusiness->uid])
            ->expectsOutputToContain('businesses processed=1')
            ->expectsOutputToContain('components installed=2')
            ->assertSuccessful();

        $this->assertSame(3, $this->installationRecordCount($betaBusiness));
        $this->assertSame(2, $this->businessOwnedRowCount($betaBusiness));
        $this->assertSame(['everywhere', 'growth_only'], $this->businessOwnedRowNames($betaBusiness));

        // ... and this second, targeted run left the first Business exactly as
        // it was, updated_at included.
        $this->assertSame($alphaRecords, $this->rawInstallationRows($alpha));
        $this->assertSame($alphaRows, $this->rawBusinessOwnedRows($alpha));
    }

    public function test_an_unresolvable_business_selector_fails_cleanly_and_writes_nothing(): void
    {
        $this->registerBlueprintTestAdapters();

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->publishBlueprint($this->discriminatorComponents());

        foreach (['999999999', 'not-a-real-business-uid'] as $selector) {
            $this->artisan('blueprint:install-missing', ['--business' => $selector])
                ->expectsOutputToContain('No Business matches the supplied --business selector.')
                // It returned before the loop: there was no sweep to summarise.
                ->doesntExpectOutputToContain('Niche Blueprint installation sweep complete.')
                ->assertFailed();
        }

        // A sweep WOULD have installed here, which is what makes "wrote
        // nothing" a real claim rather than a description of an empty
        // database: an unresolved selector never falls back to every Business.
        $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->count());
        $this->assertSame(0, CrmPipeline::query()->count());

        $this->artisan('blueprint:install-missing', ['--business' => (string) $business->id])
            ->expectsOutputToContain('components installed=2')
            ->assertSuccessful();

        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // §9.1's precondition, swept — one Business's abort ends nothing
    // =====================================================================

    public function test_a_business_with_no_plan_assignment_is_left_alone_and_the_sweep_continues(): void
    {
        $this->registerBlueprintTestAdapters();

        // Created FIRST, so `orderBy('id')` reaches it BEFORE the healthy one:
        // an abort part-way through must not end the run.
        [, $unplanned] = $this->businessWithoutPlan();
        [, $healthy] = $this->tenant(WorkspacePlanTier::Growth, 'Healthy Studios', 'Healthy');

        $this->publishBlueprint($this->discriminatorComponents());

        $this->assertLessThan((int) $healthy->id, (int) $unplanned->id);

        $this->artisan('blueprint:install-missing')
            ->expectsOutputToContain("business_id={$unplanned->id} aborted: workspace_plan_unassigned")
            ->expectsOutputToContain('businesses processed=2')
            ->expectsOutputToContain('runs aborted before any write=1')
            ->expectsOutputToContain('components installed=2')
            ->expectsOutputToContain('components skipped (unentitled)=0')
            ->expectsOutputToContain('components skipped (unavailable)=1')
            ->expectsOutputToContain('components failed=0')
            // An abort is an ordinary, supported state, not a failed sweep.
            ->assertSuccessful();

        // Not even a skip for the unplanned Business: a skip is never revisited
        // by a later automated run, so recording one here would permanently
        // deny it its own initial installation.
        $this->assertSame(0, $this->installationRecordCount($unplanned));
        $this->assertSame(0, $this->businessOwnedRowCount($unplanned));

        // And the Business AFTER it in the sweep was still installed.
        $records = $this->installationRecords($healthy);

        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['everywhere']->state);
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['growth_only']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $records['planned']->state);
        $this->assertSame(3, $this->installationRecordCount($healthy));
        $this->assertSame(['everywhere', 'growth_only'], $this->businessOwnedRowNames($healthy));
    }

    // =====================================================================
    // §12.C — this sub-slice's own scope discipline
    // =====================================================================

    public function test_this_sub_slice_ships_no_customer_surface_and_no_production_adapter(): void
    {
        // The control: the same mechanism resolves a class this sub-slice DOES
        // ship, so the three refusals below are genuine absence rather than a
        // misspelled namespace that would make this test vacuous.
        $this->assertTrue(class_exists(\App\Console\Commands\InstallMissingBlueprintComponentsCommand::class));

        $this->assertFalse(
            class_exists('App\Http\Controllers\Customer\Business\NicheBlueprintController'),
            'The customer HTTP surface is Sub-slice E, not this one.'
        );
        $this->assertFalse(
            class_exists('App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter'),
            'The first real adapter is Sub-slice D, not this one.'
        );

        // Neither name appears anywhere under app/ either, so the absence is
        // of the surface itself and not merely of one class file.
        foreach (['NicheBlueprintController', 'CrmPipelineComponentAdapter'] as $symbol) {
            $this->assertSame([], $this->appFilesMentioning($symbol));
        }

        // A container booted with no test registration has NO adapter at all:
        // §10's registry "ships empty, deliberately", so nothing in this slice
        // can install anything into a real Business until Sub-slice D
        // registers the first real adapter.
        $this->assertSame([], app(BlueprintComponentAdapterRegistry::class)->registeredComponentTypes());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The three-way feature discriminator as Blueprint components: one that
     * installs at every tier, one Growth-only, one Planned everywhere.
     *
     * @return list<array{key: string, feature: string}>
     */
    private function discriminatorComponents(): array
    {
        return [
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ];
    }

    /**
     * The raw persisted columns of every installation record, oldest first —
     * what the "byte-identical, updated_at included" proofs compare.
     *
     * @return list<array<string, mixed>>
     */
    private function rawInstallationRows(Business $business): array
    {
        return BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get()
            ->map(fn (BusinessBlueprintComponentInstallation $record): array => $record->getAttributes())
            ->all();
    }

    /**
     * The raw persisted columns of every Business-owned row an install wrote.
     *
     * @return list<array<string, mixed>>
     */
    private function rawBusinessOwnedRows(Business $business): array
    {
        return CrmPipeline::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get()
            ->map(fn (CrmPipeline $pipeline): array => $pipeline->getAttributes())
            ->all();
    }

    /** @return list<string> the Business-owned rows' names, oldest first. */
    private function businessOwnedRowNames(Business $business): array
    {
        return CrmPipeline::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /** @return list<string> every app/ file whose source mentions $symbol. */
    private function appFilesMentioning(string $symbol): array
    {
        $root = dirname(__DIR__, 3) . '/app';

        $this->assertDirectoryExists($root);

        $matches = [];
        $scanned = 0;

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            if (str_contains((string) file_get_contents($file->getPathname()), $symbol)) {
                $matches[] = $file->getPathname();
            }
        }

        // The scan really walked the tree; an empty result above is absence,
        // not a directory iterator that found nothing to read.
        $this->assertGreaterThan(100, $scanned);

        sort($matches);

        return $matches;
    }
}
