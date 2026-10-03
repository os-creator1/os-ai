<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Independent review correction: the "Marketing Content" admin page must
 * be reachable from a discoverable sidebar link, not only by typing the
 * URL — and that link must respect the exact same 'general settings'
 * authorization boundary as the page itself (App\Helpers\Helper::menuData()).
 */
class MarketingContentAdminMenuTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumeSuperAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_an_admin_without_general_settings_does_not_see_the_marketing_content_link(): void
    {
        $this->actingAsAdmin(['access backend']);

        $html = $this->get(route('admin.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.marketing-content.index'), $html);
        $this->assertStringNotContainsString('Marketing Content', $html);
    }

    public function test_an_admin_without_general_settings_cannot_open_the_marketing_content_page(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.marketing-content.index'))->assertUnauthorized();
    }

    public function test_an_admin_with_general_settings_sees_and_can_open_the_marketing_content_link(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $html = $this->get(route('admin.home'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.marketing-content.index'), $html);
        $this->assertStringContainsString('Marketing Content', $html);

        $this->get(route('admin.marketing-content.index'))->assertOk();
    }
}
