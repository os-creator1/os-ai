<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteSectionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A — contract §37.13 (Forms/Analytics
 * boundary) and §37.14 (Custom-domain boundary), combined into one file
 * since both were originally pure absence-proofs. The Forms quote-request
 * slice explicitly, narrowly lifts the Forms half of §37.13(a): exactly
 * one closed `form` section type, backed by the smallest reusable Forms
 * foundation (WebsiteForm/WebsiteFormSubmission) — never an open-ended
 * form-builder, a generic leads product, or Website-specific analytics.
 * These tests fail loudly the moment scope creeps beyond that.
 *
 * §37.14 (Custom-domain boundary) has since been NARROWLY, deliberately
 * lifted too — Slice B's own custom-domain connection feature
 * (App\Library\Website\Domains\*, App\Models\WebsiteDomain,
 * App\Http\Middleware\ResolveCustomDomainWebsite). The `website_domains`
 * table and the DNS/TLS/certificate vocabulary that goes with it are the
 * ONE now-authorized exception; every other guard in this file — no
 * generic form builder, no analytics tables, and no hostname/domain
 * ROUTE (the feature is middleware-based, never `Route::domain()`) —
 * still holds exactly as before.
 */
class WebsiteBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    // ---------------------------------------------------------------
    // §37.13 (a) — exactly the Forms foundation's own routes; no
    // generic form-builder, no separate "leads" product
    // ---------------------------------------------------------------

    public function test_only_the_forms_foundations_own_routes_exist_no_generic_form_builder(): void
    {
        $expected = [
            'customer.workspaces.businesses.website.forms.index',
            'customer.workspaces.businesses.website.forms.store',
            'customer.workspaces.businesses.website.forms.submissions',
            'public.website.form.submit',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(Route::has($name), "Expected route [{$name}] to exist for the Forms slice.");
        }

        $stillForbidden = [
            'customer.workspaces.businesses.website.forms.create',
            'customer.workspaces.businesses.website.forms.edit',
            'customer.workspaces.businesses.website.forms.update',
            'customer.workspaces.businesses.website.form-builder',
            'customer.workspaces.businesses.website.leads.index',
            'public.website.forms.submit',
            'public.website.lead.submit',
        ];

        foreach ($stillForbidden as $name) {
            $this->assertFalse(Route::has($name), "Expected no route named [{$name}] — the Forms slice ships one fixed preset, not a builder.");
        }
    }

    /**
     * Website Builder redesign added exactly two more closed cases
     * (`backdrops`, `custom_section`) — both, like `gallery`/`form`
     * before them, built entirely server-side and never AI-authored
     * (WebsitePageStrategy::withoutAiUnfillableSections()). The set
     * remains closed at 12, not open-ended.
     */
    public function test_website_section_type_enum_has_exactly_the_twelve_known_cases_and_no_generic_form_variant(): void
    {
        $values = array_map(static fn (WebsiteSectionType $case) => $case->value, WebsiteSectionType::cases());

        sort($values);

        $this->assertSame(
            ['backdrops', 'contact_details', 'cta', 'custom_section', 'faq', 'form', 'gallery', 'hero', 'image_text', 'services', 'testimonials', 'text'],
            $values
        );

        foreach (['contact_form', 'lead_form', 'form_builder'] as $forbidden) {
            $this->assertNotContains($forbidden, $values, "Section type [{$forbidden}] must not exist — only the one closed `form` type does.");
        }
    }

    public function test_no_website_form_builder_table_exists(): void
    {
        foreach (['website_form_fields', 'website_form_builder_fields', 'leads'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table [{$table}] must not exist — form field config lives in website_forms.fields JSON.");
        }
    }

    // ---------------------------------------------------------------
    // §37.13 (b) — no Website-specific analytics/event table, and no
    // stray migration hiding under the Slice A date-prefix
    // ---------------------------------------------------------------

    public function test_no_website_analytics_tables_exist(): void
    {
        foreach (['website_analytics', 'website_page_views', 'website_events', 'website_visits'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table [{$table}] must not exist in Slice A.");
        }
    }

    public function test_exactly_five_website_migrations_exist_under_the_slice_a_date_prefix(): void
    {
        $files = File::glob(database_path('migrations/2026_09_07_1300*_*.php'));

        $this->assertCount(
            5,
            $files,
            'Expected exactly the 5 known Website Slice A migrations under the 2026_09_07_1300* prefix, found: ' . implode(', ', array_map('basename', $files))
        );
    }

    // ---------------------------------------------------------------
    // §37.14 (c) — no hostname-based Website resolver, no domains table
    // ---------------------------------------------------------------

    public function test_public_website_routes_are_limited_to_the_sites_prefix_keyed_by_public_id(): void
    {
        $routesFile = base_path('routes/public.php');
        $this->assertTrue(File::exists($routesFile));

        $contents = File::get($routesFile);

        // The three Website public routes all live under Route::prefix('sites')
        // and are all bound to {website:public_id} (never a bare domain/host
        // parameter). Assert the three expected route names are present and
        // that no hostname/domain-oriented Website route name exists.
        foreach (['public.website.home', 'public.website.sitemap', 'public.website.page'] as $name) {
            $this->assertTrue(Route::has($name), "Expected route [{$name}] to be registered.");
            $this->assertStringContainsString($name, $contents);
        }

        $this->assertStringContainsString("Route::prefix('sites')", $contents);

        foreach ([
            'public.website.domain',
            'public.website.custom-domain',
            'public.website.hostname',
        ] as $forbidden) {
            $this->assertFalse(Route::has($forbidden), "Expected no route named [{$forbidden}] to exist in Slice A.");
        }
    }

    public function test_website_domains_table_exists_for_the_now_deliberately_built_slice_b(): void
    {
        // Was test_no_website_domains_table_exists in Slice A. Slice B's
        // custom-domain connection feature deliberately built this table
        // (App\Models\WebsiteDomain, database/migrations/
        // 2026_10_08_120000_create_website_domains_table.php) — flipped,
        // never removed, once that work was explicitly commissioned.
        $this->assertTrue(Schema::hasTable('website_domains'));
    }

    // ---------------------------------------------------------------
    // §37.14 (d) — no TLS/DNS/ACME/CNAME automation anywhere in the
    // ORIGINAL Slice A Website library/controller files. Slice B's own
    // App\Library\Website\Domains\* is the one deliberate, contained
    // exception (DnsVerifier, ForgeDomainProvisioner) and is
    // intentionally NOT part of this scan — this proves the vocabulary
    // never leaked into the surrounding Slice A surface, not that it
    // doesn't exist anywhere in the Website feature at all.
    // ---------------------------------------------------------------

    public function test_no_tls_dns_acme_or_cname_code_exists_in_the_website_feature(): void
    {
        $files = array_merge(
            File::glob(app_path('Library/Website/*.php')),
            [
                app_path('Http/Controllers/Public/WebsiteController.php'),
                app_path('Http/Controllers/Customer/Business/WebsiteController.php'),
            ]
        );

        $this->assertNotEmpty($files, 'Expected to find Website feature source files to scan.');

        $forbiddenSubstrings = ['DNS', 'ACME', 'TLS', 'SSL', 'CNAME', 'letsencrypt'];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $contents = File::get($file);

            foreach ($forbiddenSubstrings as $needle) {
                $this->assertFalse(
                    stripos($contents, $needle) !== false,
                    "Forbidden substring [{$needle}] found in " . basename($file) . " — no TLS/DNS/ACME automation may exist in Slice A."
                );
            }
        }
    }
}
