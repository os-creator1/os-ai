<?php

namespace Tests\Feature\Usage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §10 — business_usage_addon_catalog contains exactly zero
 * rows immediately after every M4 migration runs, and no seeder class of
 * any kind exists for this table.
 */
class AddonCatalogZeroSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_addon_catalog_is_empty_immediately_after_migration(): void
    {
        $this->assertSame(0, DB::table('business_usage_addon_catalog')->count());
    }

    public function test_no_seeder_class_targets_the_addon_catalog_table(): void
    {
        $seederFiles = glob(database_path('seeders/*.php')) ?: [];

        foreach ($seederFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('business_usage_addon_catalog', $contents, "Seeder {$file} must not reference business_usage_addon_catalog.");
        }

        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];

        foreach ($migrationFiles as $file) {
            if (! str_contains($file, 'business_usage_addon_catalog')) {
                continue;
            }

            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('->insert(', $contents, "Migration {$file} must not insert any business_usage_addon_catalog row.");
        }
    }
}
