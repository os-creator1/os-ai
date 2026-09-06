<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §19 (Index/render). Proves the rebuilt
 * one-page, six-section screen actually replaced the legacy 9-tab vendor
 * shell — not merely that some page at the same URL returns 200.
 */
class PlatformSettingsIndexRenderTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_authorized_admin_can_render_the_settings_page(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings', 'authentication settings', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
    }

    public function test_all_six_b3_sections_are_represented(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings', 'authentication settings', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
        foreach (['platform', 'appearance', 'email', 'security', 'ai', 'advanced'] as $section) {
            $response->assertSee('platform-settings-tabs-' . $section, false);
        }
    }

    public function test_legacy_notification_pusher_dlt_gateway_tab_shell_is_absent(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings', 'authentication settings', 'manage ai_settings']);

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="notifications"', $html);
        $this->assertStringNotContainsString('id="pusher"', $html);
        $this->assertStringNotContainsString('id="dlt"', $html);
        $this->assertStringNotContainsString('id="gateway-wise-billing"', $html);
        $this->assertStringNotContainsString('id="cron-job"', $html);
        $this->assertStringNotContainsString('name="broadcast_driver"', $html);
        $this->assertStringNotContainsString('name="notification_sms_gateway"', $html);
    }

    public function test_m2_component_markup_is_used_not_a_legacy_jquery_tab_shell(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings', 'authentication settings', 'manage ai_settings']);

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('ds-card', $html);
        $this->assertStringContainsString('ds-tabs', $html);
        $this->assertStringContainsString('ds-field', $html);
        $this->assertStringNotContainsString('nav-justified', $html);
    }

    public function test_no_orphan_ai_brain_link_anywhere_on_the_page(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings', 'authentication settings', 'manage ai_settings']);

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('ai-brain', $html);
    }

    public function test_a_scoped_admin_holding_only_manage_ai_settings_can_still_reach_the_page(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
        $response->assertSee('platform-settings-tabs-ai', false);
        $response->assertDontSee('platform-settings-tabs-platform', false);
    }
}
