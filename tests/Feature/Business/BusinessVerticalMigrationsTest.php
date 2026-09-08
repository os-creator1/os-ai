<?php

namespace Tests\Feature\Business;

use App\Models\BusinessVertical;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §6.1/§6.2, §16 Slice 2 -- schema
 * introspection for the 2 Slice 2 migrations, plus a standalone
 * down()/up() round-trip.
 */
class BusinessVerticalMigrationsTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    public function test_both_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('business_verticals'));
        $this->assertTrue(Schema::hasTable('question_packs'));
    }

    public function test_business_verticals_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('business_verticals', ['key', 'display_name', 'broad_industry', 'is_active']));
    }

    public function test_question_packs_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('question_packs', ['key', 'applies_to_industry', 'applies_to_vertical_key', 'version', 'questions', 'is_active']));
    }

    public function test_business_verticals_key_is_unique(): void
    {
        $this->createVertical(['key' => 'roofing']);

        $this->expectException(QueryException::class);
        DB::table('business_verticals')->insert([
            'key' => 'roofing',
            'display_name' => 'Duplicate',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_question_packs_key_and_version_are_unique_together(): void
    {
        $this->createQuestionPack(['key' => 'general', 'version' => 1]);

        $this->expectException(QueryException::class);
        DB::table('question_packs')->insert([
            'key' => 'general',
            'version' => 1,
            'questions' => '[]',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_question_packs_key_allows_a_second_version(): void
    {
        $this->createQuestionPack(['key' => 'general', 'version' => 1]);
        $v2 = $this->createQuestionPack(['key' => 'general', 'version' => 2]);

        $this->assertSame(2, $v2->version);
        $this->assertSame(2, \App\Models\QuestionPack::where('key', 'general')->count());
    }

    public function test_applies_to_vertical_key_is_foreign_keyed_to_business_verticals(): void
    {
        $this->expectException(QueryException::class);
        DB::table('question_packs')->insert([
            'key' => 'orphan',
            'applies_to_vertical_key' => 'does_not_exist',
            'version' => 1,
            'questions' => '[]',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Standalone round-trip: runs the real down()/up() dance for both
     * Slice 2 migrations in isolation.
     */
    public function test_migrations_reverse_and_replay_cleanly_in_isolation(): void
    {
        $paths = [
            'verticals' => database_path('migrations/2026_09_10_120001_create_business_verticals_table.php'),
            'packs' => database_path('migrations/2026_09_10_120002_create_question_packs_table.php'),
        ];

        $verticals = require $paths['verticals'];
        $packs = require $paths['packs'];

        try {
            $packs->down();
            $this->assertFalse(Schema::hasTable('question_packs'));

            $verticals->down();
            $this->assertFalse(Schema::hasTable('business_verticals'));
        } finally {
            $verticals->up();
            $packs->up();
        }

        $this->assertTrue(Schema::hasTable('business_verticals'));
        $this->assertTrue(Schema::hasTable('question_packs'));
    }
}
