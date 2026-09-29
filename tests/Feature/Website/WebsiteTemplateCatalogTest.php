<?php

namespace Tests\Feature\Website;

use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion. Proves the operator-owned
 * template catalog seeds exactly four templates, validates its own
 * bounded theme/manifest shape at save time (never at request time),
 * and that an inactive template is not selectable.
 */
class WebsiteTemplateCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_four_templates_are_seeded(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);

        $keys = WebsiteTemplate::pluck('key')->sort()->values()->all();

        $this->assertSame([
            'photo_booth_conversion',
            'photo_booth_editorial',
            'photo_booth_luxury',
            'photo_booth_modern',
        ], $keys);
    }

    public function test_seeded_templates_share_the_same_page_manifest_regardless_of_visual_theme(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);

        $manifests = WebsiteTemplate::pluck('page_manifest')->map(fn ($manifest) => $manifest)->all();

        $this->assertCount(4, $manifests);
        foreach ($manifests as $manifest) {
            $this->assertSame($manifests[0], $manifest, 'SEO architecture (page manifest) must not depend on the visual template.');
        }
    }

    public function test_invalid_theme_value_fails_closed_at_save_time(): void
    {
        $this->expectException(ValidationException::class);

        WebsiteTemplate::create([
            'key' => 'broken_template',
            'display_name' => 'Broken',
            'theme' => [
                'primary_color' => '#000',
                'secondary_color' => '#fff',
                'content_width' => '1000px',
                'header_variant' => 'not-a-real-variant',
            ],
            'page_manifest' => WebsiteTemplateSeeder::templates()[0]['page_manifest'],
        ]);
    }

    public function test_manifest_referencing_an_unknown_section_type_fails_closed(): void
    {
        $this->expectException(ValidationException::class);

        WebsiteTemplate::create([
            'key' => 'broken_manifest',
            'display_name' => 'Broken',
            'theme' => WebsiteTemplateSeeder::templates()[0]['theme'],
            'page_manifest' => [
                'pages' => [
                    ['page_type' => 'home', 'is_home' => true, 'allowed_section_types' => ['hero', 'video_wall']],
                ],
            ],
        ]);
    }

    public function test_inactive_template_is_not_selectable(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);
        WebsiteTemplate::where('key', 'photo_booth_luxury')->update(['is_active' => false]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        WebsiteTemplate::findActiveOrFail('photo_booth_luxury');
    }

    public function test_manifest_version_starts_at_one_and_is_operator_incrementable(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);

        $template = WebsiteTemplate::where('key', 'photo_booth_modern')->firstOrFail();
        $this->assertSame(1, $template->manifest_version);

        $template->update(['manifest_version' => 2]);
        $this->assertSame(2, $template->fresh()->manifest_version);
    }
}
