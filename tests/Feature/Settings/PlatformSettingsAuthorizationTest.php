<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §13/§19 (Authorization). This app's own
 * Handler::render() maps both AuthenticationException and
 * AuthorizationException to a rendered 401 (not 403) whenever
 * config('app.env') !== 'local' -- pre-existing, unrelated to B3,
 * confirmed by direct inspection of app/Exceptions/Handler.php and
 * already the convention every other settings/security test in this
 * repository follows.
 */
class PlatformSettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_guest_cannot_render_settings_page(): void
    {
        $this->get('/admin/settings')->assertStatus(401);
    }

    public function test_admin_lacking_every_section_ability_cannot_render_settings_page(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->get('/admin/settings')->assertStatus(401);
    }

    public function test_general_write_requires_general_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload())->assertStatus(401);
    }

    public function test_email_write_requires_system_email_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.email'), $this->baseSystemEmailPayload())->assertStatus(401);
    }

    public function test_authentication_write_requires_authentication_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.authentication'), $this->baseAuthenticationPayload())->assertStatus(401);
    }

    public function test_ai_write_requires_manage_ai_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload())->assertStatus(401);
    }

    public function test_ai_toggle_requires_manage_ai_settings_ability_independently_of_backend_access(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.ai-settings.toggle'), ['openai_enabled' => 'true'])->assertStatus(401);
    }

    public function test_maintenance_post_requires_manage_maintenance_mode_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post('/admin/maintenance-mode', ['status' => 'off'])->assertStatus(401);
    }

    public function test_permissions_write_requires_authentication_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.permissions'), ['permissions' => ['access_backend']])->assertStatus(401);
    }

    public function test_branding_remove_requires_general_settings_ability(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.branding.remove'), ['asset' => 'app_logo'])->assertStatus(401);
    }
}
