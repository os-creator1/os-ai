<?php

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * The compiled assets must actually reach a rendered page — through the
 * real application, not merely by existing in the manifest.
 *
 * This is the regression guard for the failure that produced 909 errors
 * on the pre-remediation baseline. resources/views/panels/scripts.blade.php
 * deliberately THROWS MixFileNotFoundException when
 * /js/core/theme-tokens.js is missing from the manifest and
 * config('app.debug') is true, and merely REPORTS it when debug is false.
 * So the two debug modes fail in completely different ways:
 *
 *   * with debug true  — every page-rendering test errors, with a message
 *     that points at a Blade view rather than at the asset toolchain;
 *   * with debug false — the page still renders, silently referencing an
 *     asset path that 404s in a browser. Nothing fails, and the missing
 *     script is only noticed by a user.
 *
 * Both are asserted here, authenticated and unauthenticated, so neither
 * mode can regress unnoticed.
 */
class AssetRenderSmokeTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    /**
     * Rendered by resources/views/panels/scripts.blade.php on every page
     * that includes the admin/customer shell.
     */
    private const RENDERED_ASSETS = [
        '/js/core/app-menu.js',
        '/js/core/theme-tokens.js',
        '/js/core/app.js',
        '/js/core/scripts.js',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    /**
     * @dataProvider debugModes
     */
    public function test_an_unauthenticated_page_renders_and_references_real_assets(bool $debug): void
    {
        config(['app.debug' => $debug]);

        $response = $this->get('/login');

        $response->assertOk();

        $this->assertRenderedAssetsResolve((string) $response->getContent(), $debug);
    }

    /**
     * @dataProvider debugModes
     */
    public function test_an_authenticated_page_renders_and_references_real_assets(bool $debug): void
    {
        config(['app.debug' => $debug]);

        $this->consumeSuperAdminId();
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $response = $this->get(route('admin.home'));

        $response->assertOk();

        $this->assertRenderedAssetsResolve((string) $response->getContent(), $debug);
    }

    public static function debugModes(): array
    {
        return [
            'APP_DEBUG=true' => [true],
            'APP_DEBUG=false' => [false],
        ];
    }

    /**
     * Every shell asset must be referenced by the rendered HTML AND exist
     * on disk. Existence matters most with debug false, where a missing
     * manifest entry degrades to a silent 404 instead of an exception.
     */
    private function assertRenderedAssetsResolve(string $html, bool $debug): void
    {
        $mode = $debug ? 'APP_DEBUG=true' : 'APP_DEBUG=false';

        foreach (self::RENDERED_ASSETS as $asset) {
            $this->assertStringContainsString(
                $asset,
                $html,
                "The rendered page does not reference [{$asset}] ({$mode})."
            );

            $this->assertFileExists(
                public_path(ltrim($asset, '/')),
                "[{$asset}] is referenced by the rendered page but does not exist on disk ({$mode})."
            );
        }

        // The Geist variable font the design system's @font-face points
        // at. It was absent from the repository entirely until the asset
        // build was repaired, so the platform's own typeface 404'd.
        foreach ([
            'fonts/geist/geist-latin-wght-normal.woff2',
            'fonts/geist/geist-latin-ext-wght-normal.woff2',
        ] as $font) {
            $this->assertFileExists(public_path($font), "The Geist font [{$font}] is missing ({$mode}).");
        }

        // A stale-manifest regression would surface here rather than as a
        // confusing view exception.
        $this->assertStringNotContainsString(
            'Unable to locate Mix file',
            $html,
            "The rendered page reports a missing Mix file ({$mode})."
        );
    }
}
