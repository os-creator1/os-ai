<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §5/§6/§7/§12/§19 (Secret handling).
 * Every secret field is never rendered back into the form, and a blank
 * resubmission always preserves the existing stored secret.
 */
class PlatformSettingsSecretHandlingTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_smtp_password_is_not_rendered_in_the_email_section(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['password' => 'super-secret-value']))
            ->assertRedirect();

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-value', $html);
    }

    public function test_blank_smtp_password_preserves_the_existing_password(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['password' => 'keep-this-secret']))
            ->assertRedirect();

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['password' => '', 'host' => 'smtp.changed.test']))
            ->assertRedirect();

        $this->assertSame('keep-this-secret', $this->readEnvValue('MAIL_PASSWORD'));
        $this->assertSame('smtp.changed.test', $this->readEnvValue('MAIL_HOST'));
    }

    public function test_openai_api_key_is_not_rendered_in_the_ai_section(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload(['api_key' => 'sk-super-secret']))
            ->assertRedirect();

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('sk-super-secret', $html);
    }

    public function test_blank_openai_api_key_preserves_the_existing_key(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload(['api_key' => 'sk-keep-this']))
            ->assertRedirect();

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload(['api_key' => '', 'model' => 'gpt-4o-mini']))
            ->assertRedirect();

        $this->assertSame('sk-keep-this', $this->readEnvValue('OPENAI_API_KEY'));
        $this->assertSame('gpt-4o-mini', $this->readEnvValue('OPENAI_MODEL'));
    }

    public function test_captcha_secret_is_not_rendered_and_blank_preserves_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'authentication settings']);

        $this->post('/admin/settings/authentication', $this->baseAuthenticationPayload([
            'captcha_in_login' => '1',
            'captcha_site_key' => 'site-key-123',
            'captcha_secret_key' => 'keep-this-captcha-secret',
        ]))->assertRedirect();

        $html = $this->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('keep-this-captcha-secret', $html);

        $this->post('/admin/settings/authentication', $this->baseAuthenticationPayload([
            'captcha_in_login' => '1',
            'captcha_site_key' => 'site-key-456',
            'captcha_secret_key' => '',
        ]))->assertRedirect();

        $this->assertSame('keep-this-captcha-secret', $this->readEnvValue('NOCAPTCHA_SECRET'));
        $this->assertSame('site-key-456', $this->readEnvValue('NOCAPTCHA_SITEKEY'));
    }

    public function test_social_provider_secret_is_not_rendered_and_blank_preserves_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'authentication settings']);

        $this->post('/admin/settings/authentication', $this->baseAuthenticationPayload([
            'login_with_facebook' => '1',
            'facebook_client_id' => 'fb-client-id',
            'facebook_client_secret' => 'keep-this-fb-secret',
        ]))->assertRedirect();

        $html = $this->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('keep-this-fb-secret', $html);

        $this->post('/admin/settings/authentication', $this->baseAuthenticationPayload([
            'login_with_facebook' => '1',
            'facebook_client_id' => 'fb-client-id-changed',
            'facebook_client_secret' => '',
        ]))->assertRedirect();

        $this->assertSame('keep-this-fb-secret', $this->readEnvValue('FACEBOOK_CLIENT_SECRET'));
        $this->assertSame('fb-client-id-changed', $this->readEnvValue('FACEBOOK_CLIENT_ID'));
    }
}
