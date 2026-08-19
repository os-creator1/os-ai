<?php

namespace Tests\Feature\Installer;

use App\Library\Theme\PlatformThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §7/§8 item 6. Proves the fresh-
 * installer safety fix directly: `Installer.welcome` renders (through
 * the real `GET /install` route, `canInstall` middleware included) even
 * when `platform_theme_presets` does not exist yet -- the exact state a
 * genuinely fresh install is in, since `InstallerController::database()`
 * (which runs migrations) has never executed.
 *
 * Deliberately does not drop or rename any real table: MySQL DDL like
 * `DROP TABLE` implicitly commits and would break RefreshDatabase's
 * transaction-rollback isolation for every later test in the same
 * process. `Schema::hasTable('platform_theme_presets')` is partially
 * mocked instead, scoped to exactly one expected call so an unexpected
 * second Schema call fails loudly rather than silently passing.
 */
class InstallerPreDatabaseRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_style_block_fails_safe_when_the_presets_table_does_not_exist(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('platform_theme_presets')
            ->andReturn(false);

        $result = app(PlatformThemeManager::class)->currentStyleBlock();

        $this->assertNull($result);
    }

    public function test_installer_welcome_renders_ok_when_the_presets_table_does_not_exist(): void
    {
        $installedMarker = storage_path('installed');
        $backupMarker = $installedMarker . '.slice2-test-backup';
        $markerExisted = file_exists($installedMarker);

        if ($markerExisted) {
            rename($installedMarker, $backupMarker);
        }

        try {
            Schema::shouldReceive('hasTable')
                ->once()
                ->with('platform_theme_presets')
                ->andReturn(false);

            $response = $this->get(route('Installer::welcome'));

            $response->assertOk();
            $response->assertSee('System Compatibility');
        } finally {
            if ($markerExisted && file_exists($backupMarker)) {
                rename($backupMarker, $installedMarker);
            }
        }
    }
}
