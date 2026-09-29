<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Acceptance-correction Blocker 2's core mechanical proof, in isolation
 * from the full commit pipeline: WebsitePageStrategy chooses page
 * INSTANCES; the AI may only write bounded content for exactly those
 * instances. The validator itself is a pure function of ($pages, $plan,
 * $prohibitedClaims) — RefreshDatabase is only used here to match this
 * suite's own established convention for a clean app boot.
 */
class GuidedGenerationOutputValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): array
    {
        return [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => ['hero', 'cta'], 'entity' => null],
            ['page_key' => 'about', 'page_type' => 'about', 'is_home' => false, 'slug' => 'photo-booth-about', 'title' => 'About', 'allowed_section_types' => ['hero', 'text'], 'entity' => null],
        ];
    }

    private function validOutputFor(array $plan): array
    {
        return collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo',
            'meta_description' => $page['title'] . ' meta description text.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
        ])->values()->all();
    }

    public function test_exact_plan_coverage_passes_validation(): void
    {
        $validator = app(GuidedGenerationOutputValidator::class);

        $validator->validate($this->validOutputFor($this->plan()), $this->plan());

        $this->assertTrue(true); // no exception thrown
    }

    public function test_a_missing_planned_page_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        array_pop($output); // drop the 'about' page entirely

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_an_extra_invented_page_key_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[] = [
            'page_key' => 'invented_extra_page',
            'title' => 'Extra',
            'seo_title' => null,
            'meta_description' => null,
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Extra', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]],
        ];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_duplicate_page_key_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[] = $output[0]; // 'home' twice

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_section_type_outside_the_planned_pages_own_allowed_types_fails(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        // 'about' only allows hero/text — cta is not in its plan entry.
        $output[1]['sections'][] = ['type' => 'cta', 'data' => ['heading' => 'X', 'body' => null, 'buttons' => [['label' => 'Go', 'url' => 'https://example.test']]]];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_an_internal_link_to_a_real_planned_slug_resolves(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'cta', 'data' => ['heading' => 'X', 'body' => null, 'buttons' => [['label' => 'About', 'url' => '/photo-booth-about']]]];

        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
        $this->assertTrue(true);
    }

    public function test_an_internal_link_to_a_non_planned_slug_fails(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'cta', 'data' => ['heading' => 'X', 'body' => null, 'buttons' => [['label' => 'Nope', 'url' => '/not-a-real-planned-page']]]];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_prohibited_claim_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][0]['data']['heading'] = 'Guaranteed lowest price!';

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan, ['guaranteed lowest price']);
    }

    public function test_an_over_long_seo_title_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[0]['seo_title'] = str_repeat('a', 71);

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_duplicate_seo_titles_across_pages_fail_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1]['seo_title'] = $output[0]['seo_title'];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_an_asset_image_reference_in_ai_content_fails_validation(): void
    {
        $plan = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => ['hero', 'image_text'], 'entity' => null],
        ];
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'image_text', 'data' => ['heading' => 'X', 'body' => 'y', 'image' => 'some-real-asset-uid', 'image_position' => 'left']];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }
}
