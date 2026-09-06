<?php

namespace Tests\Feature\Settings;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §14/§19 (Demo mode). Every mutable B3
 * write action must fail in config('app.stage') === 'demo'. Test email
 * and default customer permissions are covered in their own dedicated
 * test files; this file covers the remaining write actions.
 */
class PlatformSettingsDemoModeTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_general_write_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_name' => 'Should Not Save']))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNotSame('Should Not Save', config('app.name'));
    }

    public function test_email_write_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['host' => 'should-not-save.test']))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNotSame('should-not-save.test', $this->readEnvValue('MAIL_HOST'));
    }

    public function test_authentication_write_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings', 'authentication settings']);

        $this->post('/admin/settings/authentication', $this->baseAuthenticationPayload(['client_registration' => '0']))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');
    }

    public function test_ai_settings_write_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload(['model' => 'should-not-save']))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNotSame('should-not-save', config('services.openai.model'));
    }

    public function test_ai_toggle_is_blocked_in_demo_mode(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $this->post(route('admin.settings.ai-settings.toggle'), ['openai_enabled' => 'true'])
            ->assertOk()
            ->assertJson(['status' => 'error']);
    }
}
