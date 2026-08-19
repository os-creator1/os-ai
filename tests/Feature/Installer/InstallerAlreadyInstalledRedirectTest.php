<?php

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §6/§8 item 7. Behavior-preservation
 * regression test: `canInstall` middleware's own gating (unrelated to
 * and untouched by the §7 theme-injection fix) must still reject
 * `GET /install` exactly as before once the application is already
 * installed. `config('installer.installedAlreadyAction')` is `''` in
 * this application, which `canInstall::handle()`'s own switch statement
 * resolves to `abort(404)` -- confirmed by direct read of
 * config/installer.php and app/Http/Middleware/canInstall.php, not
 * assumed.
 */
class InstallerAlreadyInstalledRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_welcome_404s_once_the_application_is_already_installed(): void
    {
        $installedMarker = storage_path('installed');
        $markerAlreadyExisted = file_exists($installedMarker);

        if (! $markerAlreadyExisted) {
            file_put_contents($installedMarker, '');
        }

        try {
            $response = $this->get(route('Installer::welcome'));

            $response->assertNotFound();
        } finally {
            if (! $markerAlreadyExisted && file_exists($installedMarker)) {
                unlink($installedMarker);
            }
        }
    }
}
