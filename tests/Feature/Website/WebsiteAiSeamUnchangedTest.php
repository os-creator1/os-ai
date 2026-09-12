<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.15 — the AI
 * canonical seam regression, updated for the Unified Business Home and
 * COO Decision Engine Contract §10.1/§13 (slice AI-1).
 *
 * Before AI-1, WebsiteAiGenerationClient called OpenAI directly and read
 * config('services.openai.model'). AI-1 routes it through AiGateway
 * instead (D-4: a route's concrete model lives in config('ai.routes.*'),
 * never in domain code or read ad hoc from services.openai.model). What
 * stays true, and what this file now asserts: services.openai.* remains
 * the one credential/toggle store — no new credential store, no
 * ai_settings table — and WebsiteAiGenerationClient itself still
 * constructs no provider client and calls no provider endpoint directly;
 * that now lives solely in App\Library\Ai\Providers\**.
 */
class WebsiteAiSeamUnchangedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

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

    public function test_website_ai_generation_client_no_longer_constructs_a_provider_client_itself(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteAiGenerationClient.php'));

        // The credential/toggle store is unchanged...
        $this->assertStringNotContainsString("config('ai_settings", $source);
        $this->assertStringNotContainsString("config('website", $source);

        // ...but the client itself never reaches OpenAI directly any more
        // — that construction now lives solely under
        // App\Library\Ai\Providers\**, per the AI Gateway contract's hard
        // architecture invariant.
        $this->assertStringNotContainsString('OpenAI::client', $source);
        $this->assertStringContainsString('AiGateway', $source);
    }

    public function test_website_ai_client_fails_closed_without_touching_live_network(): void
    {
        config(['services.openai.active' => false]);

        [, $business] = $this->entitledTenant();

        $this->assertNull(app(\App\Library\Website\WebsiteAiGenerationClient::class)->complete(
            [['role' => 'user', 'content' => 'test']],
            $business,
        ));
    }
}
