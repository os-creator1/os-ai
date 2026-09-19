<?php

namespace Tests\Feature\NicheBlueprint;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 20 §5.1–§5.4, §12.A — the exact schema shape of the
 * four Blueprint tables: columns, nullability, unique constraints, the two
 * STORED generated guard columns, the composite foreign key, and the
 * deliberately FK-less provenance columns.
 *
 * NO APPLICATION BEHAVIOUR IS EXERCISED HERE. Publishing (Sub-slice B) and
 * installation (Sub-slice C) do not exist yet; this file proves only what the
 * DDL itself enforces, which is exactly what Sub-slice A is responsible for.
 */
class NicheBlueprintSchemaTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    private function assertRefused(callable $write, string $why): void
    {
        try {
            $write();
            $this->fail($why);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function insertBlueprint(array $overrides = []): int
    {
        return DB::table('niche_blueprints')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'key' => 'photo_booth_' . Str::random(6),
            'display_name' => 'Photo Booth',
            'vertical_key' => null,
            'broad_industry' => 'photo_booth_service',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertVersion(int $blueprintId, int $versionNumber, string $state = 'draft', array $overrides = []): int
    {
        return DB::table('niche_blueprint_versions')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'blueprint_id' => $blueprintId,
            'version_number' => $versionNumber,
            'state' => $state,
            'notes' => null,
            'published_at' => null,
            'published_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertComponent(int $versionId, int $blueprintId, array $overrides = []): int
    {
        return DB::table('niche_blueprint_components')->insertGetId(array_merge([
            'blueprint_version_id' => $versionId,
            'blueprint_id' => $blueprintId,
            'component_key' => 'photo_booth_default_pipeline',
            'component_type' => 'crm_pipeline',
            'required_feature_key' => 'crm',
            'payload' => json_encode(['pipeline_key' => 'sales']),
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertInstallation(Business $business, int $blueprintId, array $overrides = []): int
    {
        return DB::table('business_blueprint_component_installations')->insertGetId(array_merge([
            'business_id' => $business->id,
            'blueprint_id' => $blueprintId,
            'component_key' => 'photo_booth_default_pipeline',
            'component_type' => 'crm_pipeline',
            'installed_from_version' => 1,
            'state' => 'installed',
            'required_feature_key' => 'crm',
            'decision_reason' => null,
            'installed_record_type' => 'crm_pipeline',
            'installed_record_id' => 4242,
            'error_code' => null,
            'installed_at' => now(),
            'installed_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function business(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    // ------------------------------------------------------------ table shape

    public function test_exactly_the_four_contracted_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('niche_blueprints'));
        $this->assertTrue(Schema::hasTable('niche_blueprint_versions'));
        $this->assertTrue(Schema::hasTable('niche_blueprint_components'));
        $this->assertTrue(Schema::hasTable('business_blueprint_component_installations'));

        // Sub-slice A adds no other Blueprint table — in particular no per-plan
        // Blueprint table, no Agency Blueprint copy, and no installation
        // transitions/audit table (§5.4, §15).
        $this->assertFalse(Schema::hasTable('niche_blueprint_plan_variants'));
        $this->assertFalse(Schema::hasTable('agency_niche_blueprints'));
        $this->assertFalse(Schema::hasTable('business_blueprint_component_installation_transitions'));
    }

    public function test_each_table_has_its_contracted_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('niche_blueprints', [
            'id', 'uid', 'key', 'display_name', 'vertical_key', 'broad_industry',
            'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('niche_blueprint_versions', [
            'id', 'uid', 'blueprint_id', 'version_number', 'state', 'notes',
            'published_at', 'published_by_user_id', 'draft_guard', 'published_guard',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('niche_blueprint_components', [
            'id', 'blueprint_version_id', 'blueprint_id', 'component_key',
            'component_type', 'required_feature_key', 'payload', 'position',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('business_blueprint_component_installations', [
            'id', 'business_id', 'blueprint_id', 'component_key', 'component_type',
            'installed_from_version', 'state', 'required_feature_key',
            'decision_reason', 'installed_record_type', 'installed_record_id',
            'error_code', 'installed_at', 'installed_by_user_id',
            'created_at', 'updated_at',
        ]));
    }

    // ------------------------------------- THE entitlement invariant (§5.3)

    /**
     * The single most important assertion in Sub-slice A. A nullable
     * required_feature_key would let a published component install into every
     * Business on every plan without EntitlementManager::decide() ever being
     * consulted — a Blueprint-shaped hole through Addendum §16.
     */
    public function test_component_required_feature_key_is_not_nullable(): void
    {
        $blueprintId = $this->insertBlueprint();
        $versionId = $this->insertVersion($blueprintId, 1);

        $this->assertRefused(
            fn () => $this->insertComponent($versionId, $blueprintId, ['required_feature_key' => null]),
            'A Blueprint component with a NULL required_feature_key must be refused by the database.',
        );

        $this->assertFalse($this->isNullable('niche_blueprint_components', 'required_feature_key'));
    }

    public function test_installation_required_feature_key_is_not_nullable(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();

        $this->assertRefused(
            fn () => $this->insertInstallation($business, $blueprintId, ['required_feature_key' => null]),
            'An installation record with a NULL required_feature_key must be refused by the database.',
        );

        $this->assertFalse($this->isNullable('business_blueprint_component_installations', 'required_feature_key'));
    }

    // ------------------------------------------------- Blueprint identity (§5.1)

    public function test_a_vertical_may_carry_at_most_one_blueprint_but_many_may_be_unbound(): void
    {
        DB::table('business_verticals')->insert([
            'key' => 'photo_booth',
            'display_name' => 'Photo Booth',
            'broad_industry' => 'photo_booth_service',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertBlueprint(['vertical_key' => 'photo_booth']);

        $this->assertRefused(
            fn () => $this->insertBlueprint(['vertical_key' => 'photo_booth']),
            'A second Blueprint bound to the same vertical must be refused.',
        );

        // Unbound Blueprints are the fail-closed state and may coexist freely:
        // MySQL permits many NULLs in a unique index, which is the point.
        $this->insertBlueprint(['vertical_key' => null]);
        $this->insertBlueprint(['vertical_key' => null]);
        $this->addToAssertionCount(1);
    }

    public function test_blueprint_key_and_uid_are_unique(): void
    {
        $this->insertBlueprint(['key' => 'photo_booth', 'uid' => 'fixed-uid-1']);

        $this->assertRefused(
            fn () => $this->insertBlueprint(['key' => 'photo_booth']),
            'Two Blueprints may not share a key.',
        );

        $this->assertRefused(
            fn () => $this->insertBlueprint(['uid' => 'fixed-uid-1']),
            'Two Blueprints may not share a uid.',
        );
    }

    // --------------------------------------------- version guards (§5.2)

    /**
     * The contracted DB-enforced lifecycle: at most one draft and at most one
     * published version per Blueprint, via the two STORED generated guard
     * columns. Superseded rows yield NULL in both and may coexist without
     * limit — that is how a Blueprint retains every retired version as
     * provenance.
     */
    public function test_a_blueprint_holds_at_most_one_draft_and_one_published_version(): void
    {
        $blueprintId = $this->insertBlueprint();
        $draftId = $this->insertVersion($blueprintId, 1, 'draft');

        $this->assertRefused(
            fn () => $this->insertVersion($blueprintId, 2, 'draft'),
            'A second draft must be refused by the draft guard.',
        );

        $this->insertVersion($blueprintId, 3, 'superseded');
        $this->insertVersion($blueprintId, 4, 'superseded');
        $this->addToAssertionCount(1);

        DB::table('niche_blueprint_versions')->where('id', $draftId)->update(['state' => 'published']);

        $this->assertRefused(
            fn () => $this->insertVersion($blueprintId, 5, 'published'),
            'A second published version must be refused by the published guard.',
        );

        // A different Blueprint is unaffected: the guards are per-Blueprint.
        $other = $this->insertBlueprint();
        $this->insertVersion($other, 1, 'draft');
        $this->insertVersion($other, 2, 'published');
        $this->addToAssertionCount(1);
    }

    public function test_guard_columns_are_stored_generated_and_track_state(): void
    {
        $blueprintId = $this->insertBlueprint();
        $versionId = $this->insertVersion($blueprintId, 1, 'draft');

        $row = DB::table('niche_blueprint_versions')->where('id', $versionId)->first();
        $this->assertSame($blueprintId, (int) $row->draft_guard);
        $this->assertNull($row->published_guard);

        DB::table('niche_blueprint_versions')->where('id', $versionId)->update(['state' => 'published']);
        $row = DB::table('niche_blueprint_versions')->where('id', $versionId)->first();
        $this->assertNull($row->draft_guard);
        $this->assertSame($blueprintId, (int) $row->published_guard);

        DB::table('niche_blueprint_versions')->where('id', $versionId)->update(['state' => 'superseded']);
        $row = DB::table('niche_blueprint_versions')->where('id', $versionId)->first();
        $this->assertNull($row->draft_guard);
        $this->assertNull($row->published_guard);

        // Proof they are generated, not ordinary columns: MySQL refuses a write.
        $this->assertRefused(
            fn () => DB::table('niche_blueprint_versions')->where('id', $versionId)->update(['draft_guard' => 1]),
            'A STORED generated column must not be writable.',
        );

        foreach (['draft_guard', 'published_guard'] as $column) {
            $this->assertNotSame(
                '',
                $this->generationExpression('niche_blueprint_versions', $column),
                $column . ' must be a generated column.'
            );
            // MySQL reports STORED generated columns as "STORED GENERATED"
            // in information_schema.COLUMNS.EXTRA (as opposed to "VIRTUAL
            // GENERATED"). Asserting STORED specifically matters: a VIRTUAL
            // column cannot carry a unique index, so the guard would silently
            // stop enforcing anything.
            $this->assertSame('STORED GENERATED', $this->extra('niche_blueprint_versions', $column));
        }
    }

    public function test_version_number_is_unique_per_blueprint(): void
    {
        $blueprintId = $this->insertBlueprint();
        $this->insertVersion($blueprintId, 1, 'superseded');

        $this->assertRefused(
            fn () => $this->insertVersion($blueprintId, 1, 'superseded'),
            'One Blueprint may not hold two versions with the same version_number.',
        );
    }

    public function test_a_blueprint_with_versions_cannot_be_deleted(): void
    {
        $blueprintId = $this->insertBlueprint();
        $this->insertVersion($blueprintId, 1, 'published');

        $this->assertRefused(
            fn () => DB::table('niche_blueprints')->where('id', $blueprintId)->delete(),
            'niche_blueprint_versions.blueprint_id is restrictOnDelete.',
        );
    }

    // ------------------------------------------- component integrity (§5.3)

    /**
     * The composite FK's whole purpose: a plain key on blueprint_version_id
     * would prove only that the version exists, and would happily accept a
     * version belonging to a DIFFERENT Blueprint than this row claims.
     */
    public function test_a_component_cannot_claim_a_version_of_another_blueprint(): void
    {
        $blueprintA = $this->insertBlueprint();
        $blueprintB = $this->insertBlueprint();
        $versionOfA = $this->insertVersion($blueprintA, 1);

        $this->insertComponent($versionOfA, $blueprintA);
        $this->addToAssertionCount(1);

        $this->assertRefused(
            fn () => $this->insertComponent($versionOfA, $blueprintB, ['component_key' => 'other']),
            'A component may not pair one Blueprint version with a different Blueprint id.',
        );
    }

    public function test_component_key_is_unique_within_a_version(): void
    {
        $blueprintId = $this->insertBlueprint();
        $versionId = $this->insertVersion($blueprintId, 1);
        $this->insertComponent($versionId, $blueprintId);

        $this->assertRefused(
            fn () => $this->insertComponent($versionId, $blueprintId),
            'One version may not hold two components with the same component_key.',
        );
    }

    /**
     * component_key is STABLE ACROSS VERSIONS — the same key must be reusable
     * by a later version of the same Blueprint, because that is what makes
     * "this Business already has this component" survive re-versioning.
     */
    public function test_the_same_component_key_may_recur_in_a_later_version(): void
    {
        $blueprintId = $this->insertBlueprint();
        $v1 = $this->insertVersion($blueprintId, 1, 'superseded');
        $v2 = $this->insertVersion($blueprintId, 2, 'published');

        $this->insertComponent($v1, $blueprintId);
        $this->insertComponent($v2, $blueprintId);

        $this->assertSame(2, DB::table('niche_blueprint_components')
            ->where('component_key', 'photo_booth_default_pipeline')->count());
    }

    public function test_deleting_a_version_takes_its_components_with_it(): void
    {
        $blueprintId = $this->insertBlueprint();
        $versionId = $this->insertVersion($blueprintId, 1);
        $this->insertComponent($versionId, $blueprintId);

        DB::table('niche_blueprint_versions')->where('id', $versionId)->delete();

        $this->assertSame(0, DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $versionId)->count());
    }

    // --------------------------------------- installation record (§5.4)

    /**
     * THE idempotency key, and the entire "never silently update or
     * reactivate" guarantee.
     */
    public function test_one_business_records_one_decision_per_blueprint_component(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();
        $this->insertInstallation($business, $blueprintId);

        $this->assertRefused(
            fn () => $this->insertInstallation($business, $blueprintId),
            'A Business may hold only one installation record per blueprint and component_key.',
        );

        // A different component_key, and a different Blueprint, are both fine.
        $this->insertInstallation($business, $blueprintId, ['component_key' => 'another_component']);
        $this->insertInstallation($business, $this->insertBlueprint());
        $this->addToAssertionCount(1);
    }

    public function test_every_contracted_installation_state_is_storable(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();

        foreach (['installed', 'skipped_unentitled', 'skipped_unavailable', 'failed'] as $i => $state) {
            $this->insertInstallation($business, $blueprintId, [
                'component_key' => 'component_' . $i,
                'state' => $state,
                'installed_record_type' => null,
                'installed_record_id' => null,
                'installed_at' => null,
            ]);
        }

        $this->assertSame(4, DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)->count());
    }

    public function test_deleting_a_business_removes_its_installation_records(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();
        $this->insertInstallation($business, $blueprintId);

        DB::table('businesses')->where('id', $business->id)->delete();

        $this->assertSame(0, DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)->count());
    }

    public function test_a_blueprint_with_installations_cannot_be_deleted(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();
        $this->insertInstallation($business, $blueprintId);

        $this->assertRefused(
            fn () => DB::table('niche_blueprints')->where('id', $blueprintId)->delete(),
            'business_blueprint_component_installations.blueprint_id is restrictOnDelete.',
        );
    }

    // --------------------------- FK-less provenance columns (§5.2, §5.4)

    /**
     * installed_record_id points ACROSS bounded contexts, and
     * installed_by_user_id / published_by_user_id must never block a
     * legitimate user-deletion feature. All three are deliberately plain
     * scalars with NO foreign key — this asserts that posture rather than
     * assuming it.
     */
    public function test_the_three_provenance_columns_carry_no_foreign_key(): void
    {
        $this->assertNoForeignKeyOn('business_blueprint_component_installations', 'installed_record_id');
        $this->assertNoForeignKeyOn('business_blueprint_component_installations', 'installed_by_user_id');
        $this->assertNoForeignKeyOn('niche_blueprint_versions', 'published_by_user_id');
    }

    public function test_installed_record_id_accepts_an_id_that_matches_no_row(): void
    {
        $business = $this->business();
        $blueprintId = $this->insertBlueprint();

        // Provenance, not a referential guarantee: a stale pointer is storable
        // by design, and nothing dereferences it to make a decision.
        $this->insertInstallation($business, $blueprintId, ['installed_record_id' => 999999999]);

        $this->assertSame(1, DB::table('business_blueprint_component_installations')
            ->where('installed_record_id', 999999999)->count());
    }

    public function test_the_business_and_blueprint_foreign_keys_do_exist(): void
    {
        // The FK-less columns above are deliberate; these three are not optional.
        $this->assertNotNull($this->foreignKeyFor('business_blueprint_component_installations', 'business_id'));
        $this->assertNotNull($this->foreignKeyFor('business_blueprint_component_installations', 'blueprint_id'));
        $this->assertNotNull($this->foreignKeyFor('niche_blueprint_versions', 'blueprint_id'));
    }

    // ------------------------------------------------ introspection helpers

    private function schemaColumn(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT IS_NULLABLE, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function isNullable(string $table, string $column): bool
    {
        return ($this->schemaColumn($table, $column)->IS_NULLABLE ?? 'YES') === 'YES';
    }

    private function extra(string $table, string $column): string
    {
        return strtoupper((string) ($this->schemaColumn($table, $column)->EXTRA ?? ''));
    }

    private function generationExpression(string $table, string $column): string
    {
        return (string) ($this->schemaColumn($table, $column)->GENERATION_EXPRESSION ?? '');
    }

    private function foreignKeyFor(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );
    }

    private function assertNoForeignKeyOn(string $table, string $column): void
    {
        $this->assertNull(
            $this->foreignKeyFor($table, $column),
            $table . '.' . $column . ' must carry no foreign key (Contract 20 §5.2/§5.4).'
        );
    }
}
