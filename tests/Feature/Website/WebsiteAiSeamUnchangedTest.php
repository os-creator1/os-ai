<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.15 — the AI
 * canonical seam regression. Mirrors
 * Tests\Feature\Settings\PlatformSettingsAiSeamTest's own pattern:
 * WebsiteAiGenerationClient reuses config('services.openai.*') exactly
 * as B3/Agency Prospecting already established, with no new config key,
 * package, or ai_settings table introduced by this feature.
 */
class WebsiteAiSeamUnchangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_services_file_declares_the_exact_pre_existing_openai_keys(): void
    {
        $source = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString("'active'       => env('OPENAI_ACTIVE', false),", $source);
        $this->assertStringContainsString("'api_key'      => env('OPENAI_API_KEY'),", $source);
        $this->assertStringContainsString("'model'        => env('OPENAI_MODEL', 'gpt-4o'),", $source);
        $this->assertStringContainsString("'organization' => env('OPENAI_ORGANIZATION'),", $source);
        $this->assertStringContainsString("'project'      => env('OPENAI_PROJECT'),", $source);
        $this->assertStringContainsString("'role'         => env('OPENAI_ROLE', 'user'),", $source);
    }

    public function test_website_ai_generation_client_reads_only_the_canonical_seam(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteAiGenerationClient.php'));

        $this->assertStringContainsString("config('services.openai.active')", $source);
        $this->assertStringContainsString("config('services.openai.api_key')", $source);
        $this->assertStringContainsString("config('services.openai.model')", $source);
        $this->assertStringNotContainsString("config('ai_settings", $source);
        $this->assertStringNotContainsString("config('website", $source);
    }

    public function test_website_ai_client_fails_closed_without_touching_live_network(): void
    {
        config(['services.openai.active' => false]);

        $this->assertNull(app(\App\Library\Website\WebsiteAiGenerationClient::class)->complete([
            ['role' => 'user', 'content' => 'test'],
        ]));
    }
}
