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
 * since both are pure absence-proofs: Slice A deliberately ships no
 * form-builder, no Website-specific analytics/event capture, and no
 * hostname-based (custom-domain) resolver or TLS/DNS/ACME automation.
 * These tests fail loudly the moment any of that scope creeps in.
 */
class WebsiteBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    // ---------------------------------------------------------------
    // §37.13 (a) — no form-builder route, no "form" section type
    // ---------------------------------------------------------------

    public function test_no_form_builder_routes_are_registered(): void
    {
        $plausibleFormRouteNames = [
            'customer.workspaces.businesses.website.forms.index',
            'customer.workspaces.businesses.website.forms.create',
            'customer.workspaces.businesses.website.forms.store',
            'customer.workspaces.businesses.website.form-builder',
            'customer.workspaces.businesses.website.leads.index',
            'public.website.form.submit',
            'public.website.forms.submit',
            'public.website.lead.submit',
        ];

        foreach ($plausibleFormRouteNames as $name) {
            $this->assertFalse(Route::has($name), "Expected no route named [{$name}] to exist in Slice A.");
        }
    }

    public function test_website_section_type_enum_has_exactly_the_eight_known_cases_and_no_form_case(): void
    {
        $values = array_map(static fn (WebsiteSectionType $case) => $case->value, WebsiteSectionType::cases());

        sort($values);

        $this->assertSame(
            ['contact_details', 'cta', 'faq', 'hero', 'image_text', 'services', 'testimonials', 'text'],
            $values
        );

        foreach (['form', 'contact_form', 'lead_form', 'form_builder'] as $forbidden) {
            $this->assertNotContains($forbidden, $values, "Section type [{$forbidden}] must not exist in Slice A.");
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

    public function test_no_website_domains_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('website_domains'));
    }

    // ---------------------------------------------------------------
    // §37.14 (d) — no TLS/DNS/ACME/CNAME automation anywhere in the
    // Website feature's library or controller code
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
