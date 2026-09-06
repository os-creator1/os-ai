<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §7/§19/§30 (AI compatibility). The
 * canonical seam config('services.openai.active'|'api_key'|'model') is
 * read directly by Customer/CampaignController::generateAIMessage() and
 * by Lane A's Agency Prospecting runtime (OpenAiAgencyProspectingClient,
 * unmerged at this base but documented as reading this exact seam) --
 * proves saving B3's AI section still exposes exactly those keys, and
 * that config/services.php itself is untouched by this change.
 */
class PlatformSettingsAiSeamTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_saving_ai_settings_exposes_the_canonical_seam(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $this->post('/admin/ai-settings', $this->baseAiSettingsPayload([
            'api_key' => 'sk-seam-probe',
            'model' => 'gpt-4o-seam',
        ]))->assertRedirect();

        $this->assertSame('sk-seam-probe', config('services.openai.api_key'));
        $this->assertSame('gpt-4o-seam', config('services.openai.model'));
    }

    public function test_toggling_ai_active_exposes_the_canonical_active_flag(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $this->post(route('admin.settings.ai-settings.toggle'), ['openai_enabled' => 'true'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertTrue(filter_var(config('services.openai.active'), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_config_services_file_declares_the_exact_pre_b3_openai_keys(): void
    {
        $source = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString("'active'       => env('OPENAI_ACTIVE', false),", $source);
        $this->assertStringContainsString("'api_key'      => env('OPENAI_API_KEY'),", $source);
        $this->assertStringContainsString("'model'        => env('OPENAI_MODEL', 'gpt-4o'),", $source);
        $this->assertStringContainsString("'organization' => env('OPENAI_ORGANIZATION'),", $source);
        $this->assertStringContainsString("'project'      => env('OPENAI_PROJECT'),", $source);
        $this->assertStringContainsString("'role'         => env('OPENAI_ROLE', 'user'),", $source);
    }
}
