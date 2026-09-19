<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\BlueprintInstallationRunResult;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\CrmPipeline;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\Feature\NicheBlueprint\Support\TestInstallingComponentAdapter;
use Tests\Feature\NicheBlueprint\Support\TestThrowingComponentAdapter;
use Tests\TestCase;

/**
 * Contract 20 §6.3/§7.1/§7.2/§7.4, Sub-slice C — the installation engine
 * itself, exercised directly with the test-only adapters.
 */
class NicheBlueprintInstallerTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerBlueprintTestAdapters();
    }

    private function installer(): NicheBlueprintInstaller
    {
        return app(NicheBlueprintInstaller::class);
    }

    public function test_the_same_canonical_blueprint_gives_each_tier_only_its_entitled_subset(): void
    {
        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);

        [, $coreBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Core Studios', 'Core');
        [, $growthBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Studios', 'Growth');

        $this->installer()->installForBusiness($coreBusiness);
        $this->installer()->installForBusiness($growthBusiness);

        $core = $this->installationRecords($coreBusiness);
        $growth = $this->installationRecords($growthBusiness);

        $this->assertSame(BlueprintComponentInstallationState::Installed, $core['everywhere']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $core['growth_only']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $core['planned']->state);

        $this->assertSame(BlueprintComponentInstallationState::Installed, $growth['everywhere']->state);
        $this->assertSame(BlueprintComponentInstallationState::Installed, $growth['growth_only']->state);
        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $growth['planned']->state);

        // One canonical Blueprint served both tiers — no per-tier variant row.
        $this->assertSame((int) $core['everywhere']->blueprint_id, (int) $growth['everywhere']->blueprint_id);

        // The subsets really differ in Business-owned state, not just records.
        $this->assertSame(1, $this->businessOwnedRowCount($coreBusiness));
        $this->assertSame(2, $this->businessOwnedRowCount($growthBusiness));
    }

    public function test_an_unentitled_component_records_a_skip_and_creates_no_business_owned_state(): void
    {
        $this->publishBlueprint([['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY]]);

        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        $this->installer()->installForBusiness($business);

        $record = $this->installationRecords($business)['growth_only'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnentitled, $record->state);
        $this->assertSame('not_entitled_by_plan', $record->decision_reason);
        $this->assertNull($record->installed_record_type);
        $this->assertNull($record->installed_record_id);
        $this->assertNull($record->installed_at);
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    public function test_a_planned_feature_records_skipped_unavailable_and_creates_no_business_owned_state(): void
    {
        $this->publishBlueprint([['key' => 'planned', 'feature' => self::FEATURE_PLANNED]]);

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->installer()->installForBusiness($business);

        $record = $this->installationRecords($business)['planned'];

        $this->assertSame(BlueprintComponentInstallationState::SkippedUnavailable, $record->state);
        $this->assertSame('platform_feature_unavailable', $record->decision_reason);
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    public function test_an_installed_component_records_its_provenance_with_no_actor(): void
    {
        $this->publishBlueprint([['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]]);

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->installer()->installForBusiness($business);

        $record = $this->installationRecords($business)['everywhere'];

        $this->assertSame(BlueprintComponentInstallationState::Installed, $record->state);
        $this->assertSame('crm_pipeline', $record->installed_record_type);
        $this->assertNotNull($record->installed_record_id);
        $this->assertNotNull($record->installed_at);
        $this->assertSame(self::FEATURE_INSTALLS_EVERYWHERE, $record->required_feature_key);
        $this->assertSame(1, (int) $record->installed_from_version);
        // §6.4 — a system install has no actor and says so.
        $this->assertNull($record->installed_by_user_id);
    }

    public function test_no_matching_blueprint_writes_nothing_at_all(): void
    {
        $this->publishBlueprint(
            [['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]],
            broadIndustry: 'home_services',
        );

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(BlueprintInstallationRunResult::ABORT_NO_BLUEPRINT, $result->abortReason);
        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    public function test_an_ambiguous_broad_industry_match_fails_closed_and_writes_nothing(): void
    {
        $this->publishBlueprint([['key' => 'first', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]], key: 'photo_booth');
        $this->publishBlueprint([['key' => 'second', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]], key: 'photo_booth_alt');

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(BlueprintInstallationRunResult::ABORT_AMBIGUOUS_BROAD_INDUSTRY, $result->abortReason);
        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // §13.7 — idempotent re-run
    // =====================================================================

    public function test_a_second_run_rewrites_no_record_and_creates_no_duplicate_business_owned_row(): void
    {
        // The Business exists BEFORE the Blueprint is published, so the §9.1
        // triggers have nothing to resolve and every write below is one this
        // test asked for explicitly.
        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);

        $first = $this->installer()->installForBusiness($business);

        $this->assertSame(1, $first->installed);
        $this->assertSame(3, $first->decided());

        $records = $this->rawInstallationRows($business);
        $rows = $this->rawBusinessOwnedRows($business);

        $this->assertCount(3, $records);
        $this->assertCount(1, $rows);

        // A later wall-clock moment, so "installed_at/updated_at unchanged" is
        // a real assertion rather than one second-resolution timestamps would
        // satisfy by accident.
        $this->travel(5)->seconds();

        $second = $this->installer()->installForBusiness($business);

        // Every component short-circuited at §7.2 step 3 — nothing re-decided.
        $this->assertSame(3, $second->alreadyDecided);
        $this->assertSame(0, $second->decided());
        $this->assertSame(0, $second->installed);

        // Same ids, same states, same installed_at, same updated_at.
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
    }

    // =====================================================================
    // §13.8 / §7.4 — partial failure, rollback, and retry
    // =====================================================================

    public function test_a_throwing_component_rolls_back_only_its_own_write_and_the_run_continues(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->publishThrowingMiddleBlueprint();

        TestThrowingComponentAdapter::resetAttempts();

        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(2, $result->installed);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, TestThrowingComponentAdapter::$installAttempts);

        $records = $this->installationRecords($business);

        // Earlier successes survive the later failure ...
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['first']->state);
        $this->assertSame('crm_pipeline', $records['first']->installed_record_type);
        $this->assertNotNull($records['first']->installed_record_id);

        // ... and the components AFTER the failure still ran, which is the
        // whole reason §7.2 refuses one enclosing transaction.
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['last']->state);
        $this->assertTrue(
            CrmPipeline::query()
                ->whereKey($records['last']->installed_record_id)
                ->where('business_id', $business->id)
                ->exists()
        );

        // The failure is durable, bounded, and carries no adapter message.
        $this->assertSame(BlueprintComponentInstallationState::Failed, $records['middle']->state);
        $this->assertSame('RuntimeException', $records['middle']->error_code);
        $this->assertLessThanOrEqual(64, mb_strlen((string) $records['middle']->error_code));
        $this->assertStringNotContainsString('Test adapter failure', (string) $records['middle']->error_code);
        $this->assertNull($records['middle']->installed_record_type);
        $this->assertNull($records['middle']->installed_record_id);
        $this->assertNull($records['middle']->installed_at);

        // THE load-bearing assertion: the throwing adapter genuinely wrote a
        // Business-owned row before it threw, and that row is gone — its own
        // per-component transaction rolled back and nothing half-written
        // survived.
        $this->assertSame(['first', 'last'], $this->businessOwnedRowNames($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));
        $this->assertSame(
            0,
            CrmPipeline::query()->where('business_id', $business->id)->where('name', 'middle')->count()
        );
    }

    public function test_a_later_run_retries_only_the_failed_component_and_clears_its_error_code(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->publishThrowingMiddleBlueprint();

        TestThrowingComponentAdapter::resetAttempts();

        $this->installer()->installForBusiness($business);

        $afterFailure = $this->installationRecords($business);

        $this->assertSame(BlueprintComponentInstallationState::Failed, $afterFailure['middle']->state);

        $middleId = (int) $afterFailure['middle']->id;
        $firstBefore = $afterFailure['first']->getAttributes();
        $lastBefore = $afterFailure['last']->getAttributes();
        $rowsBefore = $this->rawBusinessOwnedRows($business);

        $this->travel(5)->seconds();

        // A run whose throwing adapter has no failures left. The registry
        // refuses a duplicate registration by design, so this run gets its own
        // registry rather than mutating the container-wide one.
        $retry = $this->installerWith(
            new TestInstallingComponentAdapter,
            new TestThrowingComponentAdapter(failuresRemaining: 0),
        );

        $result = $retry->installForBusiness($business);

        $this->assertSame(1, $result->installed);
        $this->assertSame(0, $result->failed);
        $this->assertSame(2, $result->alreadyDecided);

        // Exactly one further adapter attempt: the failed component only.
        $this->assertSame(2, TestThrowingComponentAdapter::$installAttempts);

        $records = $this->installationRecords($business);

        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['middle']->state);
        $this->assertNull($records['middle']->error_code);
        $this->assertNotNull($records['middle']->installed_at);
        $this->assertSame('crm_pipeline', $records['middle']->installed_record_type);
        $this->assertSame($middleId, (int) $records['middle']->id, 'The failed row was rewritten, not duplicated.');
        $this->assertSame(3, $this->installationRecordCount($business));
        $this->assertTrue(
            CrmPipeline::query()
                ->whereKey($records['middle']->installed_record_id)
                ->where('business_id', $business->id)
                ->where('name', 'middle')
                ->exists()
        );

        // The already-installed components were not re-run: byte-identical
        // records, and no second Business-owned row for either of them.
        $this->assertSame($firstBefore, $records['first']->getAttributes());
        $this->assertSame($lastBefore, $records['last']->getAttributes());
        $this->assertSame($rowsBefore, array_slice($this->rawBusinessOwnedRows($business), 0, 2));
        $this->assertSame(3, $this->businessOwnedRowCount($business));
        $this->assertSame(['first', 'last', 'middle'], $this->businessOwnedRowNames($business));
    }

    // =====================================================================
    // §7.2 / §8.1 — an automated re-run never reverses a skip
    // =====================================================================

    public function test_an_upgrade_followed_by_an_automated_re_run_never_reverses_a_skip(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
        ]);

        $this->installer()->installForBusiness($business);

        $this->assertSame(
            BlueprintComponentInstallationState::SkippedUnentitled,
            $this->installationRecords($business)['growth_only']->state
        );

        $records = $this->rawInstallationRows($business);
        $rows = $this->rawBusinessOwnedRows($business);

        $this->travel(5)->seconds();

        app(EntitlementManager::class)->changePlan(
            $workspace,
            WorkspacePlanTier::Growth,
            $this->platformAdminId(),
            'upgrade',
        );

        $upgraded = $workspace->fresh();

        // The upgrade really did reverse the entitlement the skip was based on
        // — without this, "still skipped" would prove nothing.
        $this->assertTrue(
            app(EntitlementManager::class)
                ->decide($upgraded, $business->fresh(), self::FEATURE_GROWTH_ONLY, (int) $upgraded->owner_user_id)
                ->allowed
        );

        $result = $this->installer()->installForBusiness($business->fresh());

        $this->assertSame(0, $result->installed);
        $this->assertSame(0, $result->decided());
        $this->assertSame(2, $result->alreadyDecided);

        // Still a skip, on the same row, untouched — reversing one is the
        // owner's explicit action alone (§7.3), never an automated run's.
        $this->assertSame(
            BlueprintComponentInstallationState::SkippedUnentitled,
            $this->installationRecords($business)['growth_only']->state
        );
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));
    }

    // =====================================================================
    // §13.4 — a new Blueprint version touches no live Business
    // =====================================================================

    public function test_publishing_a_newer_blueprint_version_changes_nothing_a_business_already_holds(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $blueprint = $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        $this->installer()->installForBusiness($business);

        $records = $this->rawInstallationRows($business);
        $rows = $this->rawBusinessOwnedRows($business);

        $this->assertCount(1, $records);
        $this->assertCount(1, $rows);

        $this->travel(5)->seconds();

        $version2 = $this->publishNextVersion($blueprint, [
            // The same component, with a DIFFERENT payload ...
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'payload' => ['name' => 'rewritten by v2']],
            // ... plus one the Business has never seen.
            ['key' => 'brand_new', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        // The publish genuinely happened, so the inertness below is not a
        // statement about a version that was never issued.
        $this->assertSame(NicheBlueprintVersionState::Published, $version2->fresh()->state);
        $this->assertSame(2, (int) $version2->version_number);
        $this->assertSame(
            ['everywhere', 'brand_new'],
            NicheBlueprintComponent::query()
                ->where('blueprint_version_id', $version2->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('component_key')
                ->all()
        );

        // And it changed nothing this Business owns — byte-identical records
        // and rows, updated_at included.
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
        $this->assertSame(1, (int) $this->installationRecords($business)['everywhere']->installed_from_version);
        $this->assertSame(['everywhere'], $this->businessOwnedRowNames($business));

        // The new component is surfaced by a later sub-slice, never installed
        // here: it has no record at all.
        $this->assertNull($this->installationRecords($business)->get('brand_new'));
        $this->assertSame(1, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // §13.10 / §8.2 — downgrade is inert
    // =====================================================================

    public function test_a_downgrade_deletes_deactivates_and_rewrites_nothing(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'growth_only', 'feature' => self::FEATURE_GROWTH_ONLY],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);

        $this->installer()->installForBusiness($business);

        $this->assertSame(
            BlueprintComponentInstallationState::Installed,
            $this->installationRecords($business)['growth_only']->state
        );

        $records = $this->rawInstallationRows($business);
        $rows = $this->rawBusinessOwnedRows($business);

        $this->assertCount(3, $records);
        $this->assertCount(2, $rows);

        $this->travel(5)->seconds();

        app(EntitlementManager::class)->changePlan(
            $workspace,
            WorkspacePlanTier::Core,
            $this->platformAdminId(),
            'downgrade',
        );

        // The downgrade really happened: the Growth-only feature is denied now.
        $downgraded = $workspace->fresh();
        $this->assertFalse(
            app(EntitlementManager::class)
                ->decide($downgraded, $business->fresh(), self::FEATURE_GROWTH_ONLY, (int) $downgraded->owner_user_id)
                ->allowed
        );

        // Nothing deleted, nothing archived, nothing rewritten to a skip.
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));
        $this->assertSame(
            0,
            CrmPipeline::query()->where('business_id', $business->id)->whereNotNull('archived_at')->count()
        );
        $this->assertSame(
            BlueprintComponentInstallationState::Installed,
            $this->installationRecords($business)['growth_only']->state
        );

        // And a later automated run over the downgraded plan is still inert:
        // an `installed` record is never revisited, so it is never re-offered
        // or rewritten to a skipped state.
        $afterRerun = $this->installer()->installForBusiness($business->fresh());

        $this->assertSame(0, $afterRerun->decided());
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
    }

    // =====================================================================
    // §9.1 — whole-run preconditions that write nothing at all
    // =====================================================================

    public function test_a_workspace_with_no_plan_assignment_aborts_without_writing_even_a_skip(): void
    {
        [, $business] = $this->businessWithoutPlan();

        $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'planned', 'feature' => self::FEATURE_PLANNED],
        ]);

        // Resolution itself succeeds, so the abort below is genuinely about
        // the missing plan and not about a Blueprint that never matched.
        $this->assertInstanceOf(NicheBlueprint::class, $this->installer()->resolveBlueprint($business));

        $result = $this->installer()->installForBusiness($business);

        $this->assertTrue($result->wasAborted());
        $this->assertSame(BlueprintInstallationRunResult::ABORT_WORKSPACE_PLAN_UNASSIGNED, $result->abortReason);
        $this->assertSame(0, $result->decided());

        // Not even a skip: a skip is never revisited by a later automated run,
        // so recording one here would permanently deny this Business its own
        // initial installation.
        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    public function test_a_blueprint_that_was_never_published_aborts_and_writes_nothing(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $publisher = $this->blueprintPublisher();
        $adminId = $this->platformAdminId();

        $blueprint = $publisher->createBlueprint($adminId, 'photo_booth', 'Photo Booth', null, self::FIXTURE_INDUSTRY);
        $draft = $publisher->createDraftVersion($adminId, $blueprint);
        $publisher->addDraftComponent(
            $adminId,
            $draft,
            'everywhere',
            TestInstallingComponentAdapter::TYPE,
            self::FEATURE_INSTALLS_EVERYWHERE,
            ['name' => 'everywhere'],
        );

        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(BlueprintInstallationRunResult::ABORT_NO_PUBLISHED_VERSION, $result->abortReason);
        // Resolution found the Blueprint; only the published version is missing.
        $this->assertSame((int) $blueprint->id, (int) $result->blueprintId);
        $this->assertSame(0, $result->decided());
        $this->assertSame(0, $this->installationRecordCount($business));
        $this->assertSame(0, $this->businessOwnedRowCount($business));
    }

    // =====================================================================
    // §7.4 — an unresolvable adapter is a bounded component failure
    // =====================================================================

    public function test_a_component_with_no_registered_adapter_fails_bounded_and_later_components_still_run(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        // Registered while publishing, because the publisher's §6.2 gate 1
        // refuses a component_type with no adapter ...
        app(BlueprintComponentAdapterRegistry::class)->register(
            new TestInstallingComponentAdapter(self::ORPHAN_COMPONENT_TYPE)
        );

        $this->publishBlueprint([
            ['key' => 'first', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'orphan', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'type' => self::ORPHAN_COMPONENT_TYPE],
            ['key' => 'last', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        // ... and absent from the registry THIS run resolves adapters through,
        // which is the real shape of a published component whose module is not
        // registered in the running process.
        $result = $this->installerWith(new TestInstallingComponentAdapter)->installForBusiness($business);

        $this->assertSame(2, $result->installed);
        $this->assertSame(1, $result->failed);

        $records = $this->installationRecords($business);

        $this->assertSame(BlueprintComponentInstallationState::Failed, $records['orphan']->state);
        $this->assertSame('UnknownBlueprintComponentTypeException', $records['orphan']->error_code);
        $this->assertLessThanOrEqual(64, mb_strlen((string) $records['orphan']->error_code));
        $this->assertNull($records['orphan']->installed_record_id);
        $this->assertNull($records['orphan']->installed_at);
        $this->assertSame(self::ORPHAN_COMPONENT_TYPE, $records['orphan']->component_type);

        // The unknown type did not abort the run: its neighbours on both sides
        // were decided normally.
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['first']->state);
        $this->assertSame(BlueprintComponentInstallationState::Installed, $records['last']->state);
        $this->assertSame(['first', 'last'], $this->businessOwnedRowNames($business));
        $this->assertSame(2, $this->businessOwnedRowCount($business));
        $this->assertSame(3, $this->installationRecordCount($business));
    }

    // =====================================================================
    // Helpers for the cases above
    // =====================================================================

    /** A component_type no adapter is registered for at install time. */
    private const ORPHAN_COMPONENT_TYPE = 'test_unregistered';

    /**
     * §13.8's shape exactly: an installing component, a throwing one, then a
     * further installing one, so both "earlier successes survive" and "later
     * components still run" are provable from one run.
     */
    private function publishThrowingMiddleBlueprint(): void
    {
        $this->publishBlueprint([
            ['key' => 'first', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'middle', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'type' => TestThrowingComponentAdapter::TYPE],
            ['key' => 'last', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);
    }

    /**
     * An installer wired to its OWN adapter registry, for the cases where the
     * adapter set has to differ from the container-wide one: the registry
     * refuses a duplicate registration by design, and mutating the singleton
     * mid-test would leak into whatever ran next.
     */
    private function installerWith(BlueprintComponentAdapter ...$adapters): NicheBlueprintInstaller
    {
        $registry = new BlueprintComponentAdapterRegistry;

        foreach ($adapters as $adapter) {
            $registry->register($adapter);
        }

        return new NicheBlueprintInstaller(
            app(EntitlementManager::class),
            $registry,
            app(WorkspacePlanAssignmentRepository::class),
        );
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
     * The raw persisted columns of every Business-owned row an install wrote —
     * including `archived_at`, so "nothing was deactivated" is covered too.
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

    // =====================================================================
    // §8.1/§13.4 — a provisioned Business is PINNED to what it was
    // provisioned with. These two are the tests that fail if the engine ever
    // re-resolves live platform state for an established Business; without
    // the pin, the ordinary recovery re-run of §9.2 silently installs.
    // =====================================================================

    public function test_a_rerun_after_a_newer_version_is_published_still_installs_nothing_new(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $blueprint = $this->publishBlueprint([
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        $this->installer()->installForBusiness($business);

        $records = $this->rawInstallationRows($business);
        $rows = $this->rawBusinessOwnedRows($business);

        $this->publishNextVersion($blueprint, [
            ['key' => 'everywhere', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
            ['key' => 'brand_new', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        // The load-bearing difference from the publish-inertness test above:
        // the installer is RUN AGAIN afterwards, exactly as §9.2's recovery
        // command does. A component the platform added after this Business was
        // provisioned has no record, so nothing short-circuits it — only the
        // version pin keeps it out.
        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(0, $result->installed);
        $this->assertSame(1, (int) $result->versionNumber, 'The re-run must resume from the version this Business holds, never advance it to the newly published one.');

        $this->assertNull($this->installationRecords($business)->get('brand_new'));
        $this->assertSame(1, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame($records, $this->rawInstallationRows($business));
        $this->assertSame($rows, $this->rawBusinessOwnedRows($business));
    }

    public function test_a_rerun_never_installs_a_second_blueprint_after_resolution_drifts(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        // Provisioned through the broad-industry fallback, because this
        // Business has no vertical yet.
        $this->publishBlueprint([['key' => 'fallback_component', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]], key: 'broad_fallback');

        $this->installer()->installForBusiness($business);

        $records = $this->rawInstallationRows($business);
        $this->assertSame(1, $this->installationRecordCount($business));

        // The owner later completes the knowledge-profile flow and confirms a
        // vertical — an ordinary profile edit — and that vertical has its own
        // active, published Blueprint. Resolution would now prefer it.
        DB::table('business_verticals')->insert([
            'key' => 'photo_booth',
            'display_name' => 'Photo Booth',
            'broad_industry' => self::FIXTURE_INDUSTRY,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->publishBlueprint(
            [['key' => 'vertical_component', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE]],
            key: 'vertical_bound',
            broadIndustry: null,
            verticalKey: 'photo_booth',
        );

        BusinessKnowledgeProfile::query()->updateOrCreate(
            ['business_id' => $business->id],
            ['vertical_key' => 'photo_booth'],
        );

        $result = $this->installer()->installForBusiness($business);

        // The whole second Blueprint has no records of its own, so without the
        // Blueprint pin every one of its components would install into this
        // established Business.
        $this->assertSame(0, $result->installed);
        $this->assertNull($this->installationRecords($business)->get('vertical_component'));
        $this->assertSame(1, $this->installationRecordCount($business));
        $this->assertSame(1, $this->businessOwnedRowCount($business));
        $this->assertSame($records, $this->rawInstallationRows($business));
    }

    /**
     * §13.6 / §8.1 — a `skipped_unavailable` record is NEVER reversed by an
     * automated path once its PlatformFeature becomes Available and the plan
     * entitles it. Only the owner's explicit add (§7.3, Sub-slice E) may.
     *
     * Availability lives in `PlatformFeatureRegistry`'s compile-time constant,
     * so a test cannot flip a feature at runtime. It can do something exactly
     * equivalent and arguably stricter: persist the record a Planned-era run
     * would have written, for a feature that IS now Available and entitled,
     * and then prove `decide()` genuinely ALLOWS it while the automated run
     * still refuses to touch it. That isolates the property under test — the
     * terminality of a skip — from the reason the skip was first recorded.
     */
    public function test_a_skip_recorded_while_a_feature_was_planned_is_never_reversed_once_it_is_available(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $blueprint = $this->publishBlueprint([
            ['key' => 'was_planned', 'feature' => self::FEATURE_INSTALLS_EVERYWHERE],
        ]);

        // Exactly the row a run made while the feature was still Planned would
        // have left behind: state and reason written by that era's decision.
        DB::table('business_blueprint_component_installations')->insert([
            'business_id' => $business->id,
            'blueprint_id' => $blueprint->id,
            'component_key' => 'was_planned',
            'component_type' => TestInstallingComponentAdapter::TYPE,
            'installed_from_version' => 1,
            'state' => BlueprintComponentInstallationState::SkippedUnavailable->value,
            'required_feature_key' => self::FEATURE_INSTALLS_EVERYWHERE,
            'decision_reason' => 'platform_feature_unavailable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The Planned -> Available transition, proven at the authority itself:
        // this feature is now Available AND entitled by this plan, so the only
        // thing that can keep the component uninstalled is the terminality of
        // the skip record.
        $decision = app(EntitlementManager::class)->decide(
            $workspace->fresh(),
            $business->fresh(),
            self::FEATURE_INSTALLS_EVERYWHERE,
            (int) $workspace->owner_user_id,
        );

        $this->assertTrue($decision->allowed, 'The feature must genuinely be installable now, or this test proves nothing.');

        $before = $this->rawInstallationRows($business);

        $this->travel(5)->seconds();

        $result = $this->installer()->installForBusiness($business);

        $this->assertSame(0, $result->installed);
        $this->assertSame(1, $result->alreadyDecided);
        $this->assertSame(
            BlueprintComponentInstallationState::SkippedUnavailable,
            $this->installationRecords($business)['was_planned']->state
        );
        $this->assertSame($before, $this->rawInstallationRows($business), 'The skip record must not be rewritten, not even its updated_at.');
        $this->assertSame(0, $this->businessOwnedRowCount($business), 'No Business-owned state may appear from an automated path.');
    }
}
