<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\NicheBlueprint\BlueprintInstallationRunResult;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
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
}
