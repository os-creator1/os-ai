<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — the About page stays concise: a long wall of text fails
 * validation (and so takes the existing corrective retry) instead of being published.
 */
class AboutConcisenessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function plan(): array
    {
        return [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => ['hero', 'text'], 'entity' => null],
            ['page_key' => 'about', 'page_type' => 'about', 'is_home' => false, 'slug' => 'photo-booth-about', 'title' => 'About', 'allowed_section_types' => ['hero', 'text'], 'entity' => null],
        ];
    }

    private function pages(string $aboutBody): array
    {
        $page = fn (string $key, string $title, string $body) => ['page_key' => $key, 'title' => $title, 'seo_title' => $title . ' | Luma', 'meta_description' => 'About ' . $title, 'sections' => [
            ['type' => 'hero', 'data' => ['heading' => $title, 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]],
            ['type' => 'text', 'data' => ['heading' => $title, 'body' => $body]],
        ]];

        return [$page('home', 'Home', 'Welcome.'), $page('about', 'About', $aboutBody)];
    }

    public function test_a_concise_about_page_passes(): void
    {
        app(GuidedGenerationOutputValidator::class)->validate($this->pages(str_repeat('We love parties. ', 40)), $this->plan());

        $this->assertTrue(true);
    }

    public function test_a_wall_of_text_about_page_is_rejected_for_the_corrective_retry(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(GuidedGenerationOutputValidator::class)->validate($this->pages(str_repeat('We love parties. ', 100)), $this->plan());
        } catch (ValidationException $e) {
            $this->assertStringContainsString('About page is too long', collect($e->errors())->flatten()->implode(' '));

            throw $e;
        }
    }

    public function test_the_prompt_asks_for_a_concise_about_page_only_when_the_plan_has_one(): void
    {
        $client = app(\App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient::class);
        $method = new \ReflectionMethod($client, 'buildMessages');
        $method->setAccessible(true);
        [, $business] = $this->entitledTenant();

        $with = $method->invoke($client, $business, $this->plan())[0]['content'];
        $without = $method->invoke($client, $business, [$this->plan()[0]])[0]['content'];

        $this->assertStringContainsString('Keep the About page concise', $with);
        $this->assertStringNotContainsString('Keep the About page concise', $without);
    }
}
