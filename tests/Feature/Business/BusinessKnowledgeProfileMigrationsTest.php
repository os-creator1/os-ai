<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §16 Slice 1, §19 -- schema
 * introspection for the 5 Slice 1 migrations, plus a standalone
 * down()/up() round-trip proving every migration's down() reverses only
 * what its own up() added.
 */
class BusinessKnowledgeProfileMigrationsTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    public function test_all_three_new_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('business_knowledge_profiles'));
        $this->assertTrue(Schema::hasTable('business_knowledge_profile_field_states'));
        $this->assertTrue(Schema::hasTable('business_knowledge_profile_changes'));
    }

    public function test_business_knowledge_profiles_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('business_knowledge_profiles', [
            'uid',
            'business_id',
            'vertical_key',
            'pricing_method',
            'financing_available',
            'offers',
            'differentiators',
            'ideal_customers',
            'customer_problems',
            'credentials',
            'years_operating',
            'warranties_guarantees',
            'primary_conversion_goal',
            'conversion_target',
            'brand_voice',
            'prohibited_claims',
            'growth_priority_service_ids',
            'growth_priority_location_ids',
            'testimonials',
            'reviews_source',
        ]));
    }

    public function test_business_locations_table_has_hours_and_provenance_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('business_locations', [
            'hours',
            'hours_source',
            'hours_verification_status',
            'hours_verified_by_user_id',
            'hours_verified_at',
        ]));
    }

    public function test_business_knowledge_profile_field_states_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('business_knowledge_profile_field_states', [
            'business_id',
            'field_key',
            'source',
            'verification_status',
            'verified_by_user_id',
            'verified_at',
        ]));
    }

    public function test_business_knowledge_profile_changes_table_has_documented_columns_and_is_append_only(): void
    {
        $this->assertTrue(Schema::hasColumns('business_knowledge_profile_changes', [
            'business_id',
            'field_key',
            'old_value',
            'new_value',
            'source',
            'actor_user_id',
            'created_at',
        ]));

        // Append-only: no updated_at column at all (§5.3).
        $this->assertFalse(Schema::hasColumn('business_knowledge_profile_changes', 'updated_at'));
    }

    public function test_one_profile_per_business_is_enforced_by_a_database_unique_constraint(): void
    {
        [$business] = $this->profileFixtureBusiness();

        BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);

        $this->expectException(QueryException::class);

        DB::table('business_knowledge_profiles')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $business->id,
            'reviews_source' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_business_cascades_to_every_new_table(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $actorId = $this->actorUserId();

        $profile = BusinessKnowledgeProfile::create(['business_id' => $business->id, 'reviews_source' => 'none']);

        DB::table('business_knowledge_profile_field_states')->insert([
            'business_id' => $business->id,
            'field_key' => 'brand_voice',
            'verification_status' => 'unverified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('business_knowledge_profile_changes')->insert([
            'business_id' => $business->id,
            'field_key' => 'brand_voice',
            'source' => 'manual_edit',
            'actor_user_id' => $actorId,
            'created_at' => now(),
        ]);

        Business::destroy($business->id);

        $this->assertDatabaseMissing('business_knowledge_profiles', ['id' => $profile->id]);
        $this->assertSame(0, DB::table('business_knowledge_profile_field_states')->where('business_id', $business->id)->count());
        $this->assertSame(0, DB::table('business_knowledge_profile_changes')->where('business_id', $business->id)->count());
    }

    /**
     * Standalone round-trip: runs the real down()/up() dance for all 5
     * Slice 1 migrations in isolation, outside RefreshDatabase's
     * transaction (raw schema DDL implicitly commits in MySQL). Verifies
     * every migration's down() reverses cleanly and up() fully restores
     * the schema afterwards.
     */
    public function test_migrations_reverse_and_replay_cleanly_in_isolation(): void
    {
        $paths = [
            'profiles' => database_path('migrations/2026_09_09_120001_create_business_knowledge_profiles_table.php'),
            'hours' => database_path('migrations/2026_09_09_120002_add_hours_and_provenance_to_business_locations_table.php'),
            'field_states' => database_path('migrations/2026_09_09_120003_create_business_knowledge_profile_field_states_table.php'),
            'changes' => database_path('migrations/2026_09_09_120004_create_business_knowledge_profile_changes_table.php'),
            'backfill' => database_path('migrations/2026_09_09_120005_backfill_business_knowledge_profiles_for_existing_businesses.php'),
        ];

        $profiles = require $paths['profiles'];
        $hours = require $paths['hours'];
        $fieldStates = require $paths['field_states'];
        $changes = require $paths['changes'];
        $backfill = require $paths['backfill'];

        try {
            // Reverse dependency order: backfill (no-op), changes,
            // field_states, hours columns, profiles table.
            $backfill->down();

            $changes->down();
            $this->assertFalse(Schema::hasTable('business_knowledge_profile_changes'));

            $fieldStates->down();
            $this->assertFalse(Schema::hasTable('business_knowledge_profile_field_states'));

            $hours->down();
            $this->assertFalse(Schema::hasColumn('business_locations', 'hours'));
            $this->assertFalse(Schema::hasColumn('business_locations', 'hours_verified_by_user_id'));

            $profiles->down();
            $this->assertFalse(Schema::hasTable('business_knowledge_profiles'));
        } finally {
            // Forward order restores the schema so later tests in this
            // process see a matching schema.
            $profiles->up();
            $hours->up();
            $fieldStates->up();
            $changes->up();
            $backfill->up();
        }

        $this->assertTrue(Schema::hasTable('business_knowledge_profiles'));
        $this->assertTrue(Schema::hasColumn('business_locations', 'hours'));
        $this->assertTrue(Schema::hasTable('business_knowledge_profile_field_states'));
        $this->assertTrue(Schema::hasTable('business_knowledge_profile_changes'));
    }

    public function test_backfill_migration_is_idempotent_for_a_pre_existing_business(): void
    {
        [$business] = $this->profileFixtureBusiness();

        // RefreshDatabase already ran the backfill once for this Business
        // (created after migrations ran, so it wasn't actually backfilled
        // -- getOrCreate() covers new Businesses). Simulate a genuinely
        // pre-existing, not-yet-profiled Business by deleting its row,
        // then re-running the backfill migration directly.
        DB::table('business_knowledge_profiles')->where('business_id', $business->id)->delete();
        $this->assertSame(0, DB::table('business_knowledge_profiles')->where('business_id', $business->id)->count());

        $backfill = require database_path('migrations/2026_09_09_120005_backfill_business_knowledge_profiles_for_existing_businesses.php');

        $backfill->up();
        $this->assertSame(1, DB::table('business_knowledge_profiles')->where('business_id', $business->id)->count());

        // Re-running is a safe no-op: still exactly one row.
        $backfill->up();
        $this->assertSame(1, DB::table('business_knowledge_profiles')->where('business_id', $business->id)->count());
    }
}
