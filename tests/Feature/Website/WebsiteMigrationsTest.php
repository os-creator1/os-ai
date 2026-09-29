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

    public function test_website_domains_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('website_domains', [
            'uid',
            'website_id',
            'domain',
            'is_primary',
            'status',
            'verification_token',
            'failure_reason',
            'certificate_reference',
            'verified_at',
            'activated_at',
            'last_checked_at',
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
            // The Forms slice added two later tables that FK to `websites`
            // (website_forms) and to `website_forms` itself
            // (website_form_submissions). Dropping `websites` while either
            // still exists would violate their foreign key, so this test's
            // own isolation now has to unwind and rebuild them too.
            'website_form_submissions' => database_path('migrations/2026_10_06_120001_create_website_form_submissions_table.php'),
            'website_forms' => database_path('migrations/2026_10_06_120000_create_website_forms_table.php'),
            // Slice B (custom domains) added one more table that FKs
            // straight to `websites` — same reasoning, same fix.
            'website_domains' => database_path('migrations/2026_10_08_120000_create_website_domains_table.php'),
            // Website Generator + Local SEO Completion lane added one
            // more table that FKs straight to `websites` — same
            // reasoning, same fix. `website_templates` and
            // `websites.template_key` are deliberately excluded: neither
            // has any foreign-key relationship to `websites`, so neither
            // participates in this drop-order dance.
            'website_guided_generation_attempts' => database_path('migrations/2026_10_12_090002_create_website_guided_generation_attempts_table.php'),
        ];

        $websites = require $paths['websites'];
        $pages = require $paths['website_pages'];
        $revisions = require $paths['website_revisions'];
        $publishedRevisionId = require $paths['published_revision_id'];
        $assets = require $paths['website_assets'];
        $formSubmissions = require $paths['website_form_submissions'];
        $forms = require $paths['website_forms'];
        $domains = require $paths['website_domains'];
        $guidedGenerationAttempts = require $paths['website_guided_generation_attempts'];

        try {
            // Reverse order: guided generation attempts, then domains,
            // then form submissions, then forms, then assets, then the
            // published_revision_id column/FK, then revisions, then
            // pages, then websites.
            $guidedGenerationAttempts->down();
            $this->assertFalse(Schema::hasTable('website_guided_generation_attempts'));

            $domains->down();
            $this->assertFalse(Schema::hasTable('website_domains'));

            $formSubmissions->down();
            $this->assertFalse(Schema::hasTable('website_form_submissions'));

            $forms->down();
            $this->assertFalse(Schema::hasTable('website_forms'));

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
            // published_revision_id, assets, forms, form submissions,
            // domains, guided generation attempts — restore the schema so
            // RefreshDatabase's transaction rollback for THIS test
            // doesn't leave the next test file with a mismatched schema
            // (these are raw DDL changes outside any transaction).
            $websites->up();
            $pages->up();
            $revisions->up();
            $publishedRevisionId->up();
            $assets->up();
            $forms->up();
            $formSubmissions->up();
            $domains->up();
            $guidedGenerationAttempts->up();
        }

        $this->assertTrue(Schema::hasTable('websites'));
        $this->assertTrue(Schema::hasTable('website_pages'));
        $this->assertTrue(Schema::hasTable('website_revisions'));
        $this->assertTrue(Schema::hasTable('website_assets'));
        $this->assertTrue(Schema::hasTable('website_forms'));
        $this->assertTrue(Schema::hasTable('website_form_submissions'));
        $this->assertTrue(Schema::hasTable('website_domains'));
        $this->assertTrue(Schema::hasColumn('websites', 'published_revision_id'));
    }
}
