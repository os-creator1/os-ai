<?php

namespace Tests\Feature\Settings;

use App\Library\Branding\BrandingPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §4/§17/§19 (Branding remove action).
 * Wires the previously-uncalled BrandingUploadService::delete() through
 * exactly the six allowlisted logical keys RemoveBrandingAssetRequest
 * accepts. Existing upload behavior stays covered by
 * tests/Feature/Branding/BrandingUploadValidationTest.php, not
 * duplicated here.
 */
class PlatformSettingsBrandingRemoveTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    private const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    public function test_removing_a_configured_logo_falls_back_to_the_bundled_default(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $contents = base64_decode(self::VALID_PNG_BASE64);
        $file = UploadedFile::fake()->createWithContent('MyLogo.png', $contents);
        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_logo' => $file]))->assertRedirect();

        $expectedFilename = hash('sha256', $contents) . '.png';
        $this->assertFileExists(public_path("images/branding/logo/{$expectedFilename}"));

        $this->post(route('admin.settings.branding.remove'), ['asset' => 'app_logo'])->assertRedirect();

        $this->assertSame('images/branding/default-logo.svg', config('app.logo'));
    }

    public function test_foreign_asset_key_is_rejected(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $response = $this->post(route('admin.settings.branding.remove'), ['asset' => 'app_favicon.php']);

        $response->assertSessionHasErrors('asset');
    }

    public function test_arbitrary_env_key_style_value_is_rejected(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $response = $this->post(route('admin.settings.branding.remove'), ['asset' => 'MAIL_PASSWORD']);

        $response->assertSessionHasErrors('asset');
    }

    public function test_removal_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.settings.branding.remove'), ['asset' => 'app_logo'])
            ->assertRedirect()
            ->assertSessionHas('status', 'error');
    }
}
