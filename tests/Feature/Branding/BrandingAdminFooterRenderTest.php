<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingPresenter;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding correction round 1. The rendered
 * admin panel footer (resources/views/panels/footer.blade.php) must
 * render <x-branding-footer /> exactly once, with no leftover legacy
 * fragment duplicating config('app.name') as a second, spurious link
 * back to the login page. BrandingFooterRenderTest already covers the
 * <x-branding-footer /> component in isolation; this covers the actual
 * admin page that wraps it, which is what surfaced the duplication.
 */
class BrandingAdminFooterRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(BrandingPresenter::CACHE_KEY);
        $this->ensureRequiredAppConfigRowsExist();
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    public function test_admin_footer_renders_the_company_name_exactly_once_with_no_login_link(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $html = $this->get(route('admin.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<footer\b.*?</footer>#s', $html, 'Expected a <footer> element in the response.');
        preg_match('#<footer\b.*?</footer>#s', $html, $matches);
        $footerHtml = $matches[0];

        $this->assertSame(
            1,
            substr_count($footerHtml, 'AI Business OS'),
            'The footer company name must appear exactly once, not duplicated.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<a[^>]*href="[^"]*/login"[^>]*>#',
            $footerHtml,
            'The footer must not contain a spurious link back to /login.'
        );
    }

    /**
     * Remediation exception (DESIGN-SYSTEM-M2-PLATFORM-BRANDING-REMEDIATION-EXCEPTION.md):
     * panels/footer.blade.php previously appended a hardcoded, unconditional
     * legacy "All rights reserved." fragment after <x-branding-footer />,
     * so an owner-configured footer_copyright_text rendered doubled, e.g.
     * "All rights reserved worldwide All rights reserved.". <x-branding-footer />
     * must be the sole rendered copyright/company/wording line, with no
     * second, legacy suffix appended after it.
     */
    public function test_admin_footer_does_not_append_a_legacy_rights_reserved_suffix_after_the_configured_wording(): void
    {
        config([
            'app.footer_company_name' => 'Acme Test Co',
            'app.footer_copyright_text' => 'All rights reserved worldwide',
        ]);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $this->actingAsAdmin(['access backend', 'manage theme']);

        $html = $this->get(route('admin.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<footer\b.*?</footer>#s', $html, 'Expected a <footer> element in the response.');
        preg_match('#<footer\b.*?</footer>#s', $html, $matches);
        $footerHtml = $matches[0];

        $this->assertStringContainsString('All rights reserved worldwide', $footerHtml);
        $this->assertSame(
            1,
            substr_count($footerHtml, 'Acme Test Co'),
            'The footer company name must appear exactly once, not duplicated.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#All rights reserved worldwide\s*All rights reserved\.#',
            $footerHtml,
            'The configured wording must not be followed by a second, legacy "All rights reserved." suffix.'
        );
        $this->assertSame(
            0,
            substr_count($footerHtml, __('locale.labels.all_rights_reserved')),
            'The legacy locale.labels.all_rights_reserved string must not render in the footer at all — <x-branding-footer /> is the sole copyright line.'
        );
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }
}
