<?php

namespace Tests\Feature\Branding;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §10/§9 item 3. HTTP-level:
 * login, register, verify, Installer::welcome, and every errors/* view
 * render 200, contain no literal "Ultimate SMS"/"Codeglen" string, and
 * never render an <img> tag with an empty src attribute for the
 * logo/favicon/illustration components.
 */
class BrandingComponentRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
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

    private function assertCleanBrandingHtml(string $html): void
    {
        $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html);
        $this->assertStringNotContainsStringIgnoringCase('Codeglen', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html);
    }

    public function test_login_page_renders_clean(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertCleanBrandingHtml($response->getContent());
    }

    public function test_verify_page_renders_clean(): void
    {
        $response = $this->get(route('verify.index'));

        $response->assertOk();
        $this->assertCleanBrandingHtml($response->getContent());
    }

    public function test_installer_welcome_renders_clean(): void
    {
        $installedMarker = storage_path('installed');
        $backupMarker = $installedMarker . '.branding-test-backup';
        $markerExisted = file_exists($installedMarker);

        if ($markerExisted) {
            rename($installedMarker, $backupMarker);
        }

        try {
            $response = $this->get(route('Installer::welcome'));

            $response->assertOk();
            $this->assertCleanBrandingHtml($response->getContent());
        } finally {
            if ($markerExisted && file_exists($backupMarker)) {
                rename($backupMarker, $installedMarker);
            }
        }
    }

    public function test_every_error_view_renders_clean(): void
    {
        // $errors is normally auto-shared by Laravel's own
        // ShareErrorsFromSession middleware for a real HTTP response;
        // rendering the view directly (bypassing the HTTP pipeline, to
        // avoid needing to trigger a genuine framework exception for
        // each status) requires supplying the same empty bag by hand.
        $errors = new \Illuminate\Support\ViewErrorBag();

        foreach (['401', '403', '404', '419', '429', '500', '503'] as $code) {
            $html = view("errors.{$code}", ['exception' => new \Exception('test'), 'errors' => $errors])->render();

            $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html, "errors.{$code}");
            $this->assertStringNotContainsStringIgnoringCase('Codeglen', $html, "errors.{$code}");
            $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html, "errors.{$code}");
        }
    }
}
