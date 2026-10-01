<?php

namespace Tests\Feature\Website\Gallery;

use App\Library\Website\Gallery\WebsiteAssetAltTextGenerator;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round — the earlier WebsiteAssetAltTextGenerator
 * spent one AiGateway-budgeted call per uploaded photo without ever
 * sending the photograph itself. Proves the corrected version is entirely
 * deterministic: zero AI calls, ever — a mocked WebsiteAiGenerationClient
 * that would fail the test if `complete()` is invoked at all proves this
 * directly, rather than merely asserting the resulting string.
 */
class WebsiteAssetAltTextGeneratorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function bindClientThatMustNeverBeCalled(): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    private function makeAsset(Website $website, array $overrides = []): \App\Models\WebsiteAsset
    {
        return $website->assets()->create(array_merge([
            'disk' => 'public',
            'path' => 'images/websites/' . $website->uid . '/test.png',
            'mime_type' => 'image/png',
            'size' => 100,
        ], $overrides));
    }

    public function test_a_titled_asset_uses_its_own_title_verbatim_with_zero_ai_calls(): void
    {
        $this->bindClientThatMustNeverBeCalled();
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $asset = $this->makeAsset($website, ['title' => 'Open-Air booth at a summer wedding', 'category_tag' => null]);

        $altText = app(WebsiteAssetAltTextGenerator::class)->suggest($business, $asset);

        $this->assertSame('Open-Air booth at a summer wedding', $altText);
    }

    public function test_an_untitled_asset_with_a_category_composes_a_deterministic_sentence_with_zero_ai_calls(): void
    {
        $this->bindClientThatMustNeverBeCalled();
        [, $business] = $this->entitledTenant(['name' => 'Snap Booth Co']);
        $website = $this->createWebsite($business);
        $asset = $this->makeAsset($website, ['title' => null, 'category_tag' => 'open_air_booth']);

        $altText = app(WebsiteAssetAltTextGenerator::class)->suggest($business, $asset);

        $this->assertStringContainsString('open air booth', $altText);
        $this->assertStringContainsString('Snap Booth Co', $altText);
    }

    public function test_an_untitled_uncategorized_asset_gets_no_fabricated_caption(): void
    {
        $this->bindClientThatMustNeverBeCalled();
        [, $business] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['name' => '']);
        $website = $this->createWebsite($business);
        $asset = $this->makeAsset($website, ['title' => null, 'category_tag' => null]);

        $altText = app(WebsiteAssetAltTextGenerator::class)->suggest($business->fresh(), $asset);

        $this->assertNull($altText);
    }
}
