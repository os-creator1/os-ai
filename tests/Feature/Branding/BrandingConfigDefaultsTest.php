<?php

namespace Tests\Feature\Branding;

use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §5/§9 item 1. Mechanical,
 * source-level proof (immune to any local .env override, mirroring
 * AuthDesignSystemContentTest's own established pattern from Slice 2):
 * config/app.php's name/title/keyword default values contain neither
 * "Ultimate SMS" nor "Codeglen", and logo/favicon defaults point at real
 * bundled files under public/.
 */
class BrandingConfigDefaultsTest extends TestCase
{
    public function test_name_title_and_keyword_defaults_contain_no_legacy_branding(): void
    {
        // The unrelated, pre-existing 'maintenance_secret_path' key's
        // default value is coincidentally the literal string "codeglen"
        // (a maintenance-mode bypass secret, not branding text) -- out
        // of this contract's own scope, excluded here rather than
        // silently left to produce a false failure.
        $source = $this->configSourceExcludingMaintenanceSecretPath();

        $this->assertMatchesRegularExpression("/'name'\\s*=>\\s*env\\('APP_NAME',\\s*'AI Business OS'\\)/", $source);
        $this->assertMatchesRegularExpression("/'title'\\s*=>\\s*env\\('APP_TITLE',\\s*'[^']*'\\)/", $source);
        $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $source);
        $this->assertStringNotContainsStringIgnoringCase('Codeglen', $source);
    }

    private function configSourceExcludingMaintenanceSecretPath(): string
    {
        $lines = file(base_path('config/app.php'));

        return implode('', array_filter(
            $lines,
            fn ($line) => ! str_contains($line, 'maintenance_secret_path')
        ));
    }

    public function test_logo_and_favicon_defaults_point_at_real_bundled_files(): void
    {
        $source = file_get_contents(base_path('config/app.php'));

        $this->assertMatchesRegularExpression(
            "/'logo'\\s*=>\\s*env\\('APP_LOGO',\\s*'images\\/branding\\/default-logo\\.svg'\\)/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'favicon'\\s*=>\\s*env\\('APP_FAVICON',\\s*'images\\/branding\\/default-favicon\\.svg'\\)/",
            $source
        );

        $this->assertFileExists(public_path('images/branding/default-logo.svg'));
        $this->assertFileExists(public_path('images/branding/default-logo-compact.svg'));
        $this->assertFileExists(public_path('images/branding/default-favicon.svg'));
    }

    public function test_footer_text_key_no_longer_exists(): void
    {
        $source = file_get_contents(base_path('config/app.php'));

        $this->assertDoesNotMatchRegularExpression("/'footer_text'\\s*=>/", $source);
        $this->assertMatchesRegularExpression("/'footer_company_name'\\s*=>/", $source);
        $this->assertMatchesRegularExpression("/'footer_copyright_text'\\s*=>/", $source);
    }
}
