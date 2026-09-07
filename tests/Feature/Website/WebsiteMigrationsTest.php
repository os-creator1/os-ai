<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Contract §37.12 (Migrations). Schema-introspection assertions that the
 * 5 Website Generation + Hosting Slice A migrations produced the
 * documented tables/columns (relying on RefreshDatabase's own migration
 * run — this file never re-runs migration up()/down() against the
 * shared schema except inside the single isolated round-trip test,
 * which restores everything it changes before finishing).
 */
class WebsiteMigrationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_all_four_website_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('websites'));
        $this->assertTrue(Schema::hasTable('website_pages'));
        $this->assertTrue(Schema::hasTable('website_revisions'));
        $this->assertTrue(Schema::hasTable('website_assets'));

        // Proves migration 4 (add_published_revision_id_to_websites_table) ran.
        $this->assertTrue(Schema::hasColumn('websites', 'published_revision_id'));
    }

    public function test_websites_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('websites', [
            'uid',
            'public_id',
            'business_id',
            'name',
            'status',
            'theme',
            'published_revision_id',
        ]));
    }

    public function test_website_pages_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('website_pages', [
            'uid',
            'website_id',
            'title',
            'slug',
            'is_home',
            'sections',
            'seo_title',
            'meta_description',
            'noindex',
            'sort_order',
        ]));
    }

    public function test_website_revisions_table_has_documented_columns_and_is_write_once(): void
    {
        $this->assertTrue(Schema::hasColumns('website_revisions', [
            'uid',
            'website_id',
            'version_number',
            'snapshot',
            'schema_version',
            'created_by',
        ]));

        // Write-once/immutable: no updated_at column at all (contract §5.3/§33).
        $this->assertFalse(Schema::hasColumn('website_revisions', 'updated_at'));
    }

    public function test_website_assets_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('website_assets', [
            'uid',
            'website_id',
            'disk',
            'path',
            'mime_type',
            'size',
            'width',
            'height',
            'alt_text',
            'content_hash',
            'first_published_at',
        ]));
    }

    /**
     * Standalone round-trip: runs the real down()/up() dance for all 5
     * migrations in isolation, outside RefreshDatabase's transaction
     * (raw schema DDL implicitly commits in MySQL, so it cannot be
     * wrapped/rolled back by a transaction anyway). Verifies:
     *  - down() in reverse order (assets, published_revision_id column,
     *    revisions, pages, websites) drops every table/column cleanly;
     *  - migration 4's down() specifically drops the FK BEFORE the
     *    column, leaving `websites` still existing with no
     *    `published_revision_id` column (no dangling-FK error);
     *  - up() in forward order fully restores the schema afterwards, so
     *    the next test file in this process sees a matching schema.
     */
    public function test_migrations_reverse_and_replay_cleanly_in_isolation(): void
    {
        $paths = [
            'websites' => database_path('migrations/2026_09_07_130001_create_websites_table.php'),
            'website_pages' => database_path('migrations/2026_09_07_130002_create_website_pages_table.php'),
            'website_revisions' => database_path('migrations/2026_09_07_130003_create_website_revisions_table.php'),
            'published_revision_id' => database_path('migrations/2026_09_07_130004_add_published_revision_id_to_websites_table.php'),
            'website_assets' => database_path('migrations/2026_09_07_130005_create_website_assets_table.php'),
        ];

        $websites = require $paths['websites'];
        $pages = require $paths['website_pages'];
        $revisions = require $paths['website_revisions'];
        $publishedRevisionId = require $paths['published_revision_id'];
        $assets = require $paths['website_assets'];

        try {
            // Reverse order: assets, then the published_revision_id
            // column/FK, then revisions, then pages, then websites.
            $assets->down();
            $this->assertFalse(Schema::hasTable('website_assets'));

            $publishedRevisionId->down();
            $this->assertTrue(Schema::hasTable('websites'));
            $this->assertFalse(Schema::hasColumn('websites', 'published_revision_id'));

            $revisions->down();
            $this->assertFalse(Schema::hasTable('website_revisions'));

            $pages->down();
            $this->assertFalse(Schema::hasTable('website_pages'));

            $websites->down();
            $this->assertFalse(Schema::hasTable('websites'));
        } finally {
            // Forward order: websites, pages, revisions,
            // published_revision_id, assets — restore the schema so
            // RefreshDatabase's transaction rollback for THIS test
            // doesn't leave the next test file with a mismatched
            // schema (these are raw DDL changes outside any
            // transaction).
            $websites->up();
            $pages->up();
            $revisions->up();
            $publishedRevisionId->up();
            $assets->up();
        }

        $this->assertTrue(Schema::hasTable('websites'));
        $this->assertTrue(Schema::hasTable('website_pages'));
        $this->assertTrue(Schema::hasTable('website_revisions'));
        $this->assertTrue(Schema::hasTable('website_assets'));
        $this->assertTrue(Schema::hasColumn('websites', 'published_revision_id'));
    }
}
