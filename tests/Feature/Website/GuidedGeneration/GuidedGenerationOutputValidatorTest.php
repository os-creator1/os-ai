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

    /**
     * Acceptance-correction round 2, Blocker 1: an `image_text` section
     * with NO image (AI is never allowed to choose one) must be valid
     * at this pre-binding stage — the section validator's own
     * `image => required|string` rule would otherwise make an
     * `image_text` section AI writes categorically impossible to pass,
     * since asset references are separately forbidden entirely.
     */
    public function test_an_image_text_section_with_a_null_image_passes_pre_binding_validation(): void
    {
        $plan = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => ['hero', 'image_text'], 'entity' => null],
        ];
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'image_text', 'data' => ['heading' => 'Our story', 'body' => 'Founded in 2020.', 'image' => null, 'image_position' => 'left']];

        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
        $this->assertTrue(true);
    }

    /**
     * Acceptance-correction round 2, Blocker 1: omitting the `image` key
     * entirely (rather than sending an explicit null) must also pass —
     * a real provider response may do either.
     */
    public function test_an_image_text_section_with_the_image_key_omitted_passes_pre_binding_validation(): void
    {
        $plan = [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => ['hero', 'image_text'], 'entity' => null],
        ];
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'image_text', 'data' => ['heading' => 'Our story', 'body' => 'Founded in 2020.', 'image_position' => 'left']];

        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
        $this->assertTrue(true);
    }

    /**
     * Acceptance-correction round 2, Blocker 2: every plan page_key
     * present, but with zero sections — must fail, and must fail BEFORE
     * anything downstream ever sees this batch as acceptable.
     */
    public function test_an_empty_but_exact_page_batch_fails_validation(): void
    {
        $plan = $this->plan();
        $output = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => null,
            'meta_description' => null,
            'sections' => [],
        ])->values()->all();

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    /**
     * Acceptance-correction round 2, Blocker 2: a page with sections but
     * no hero (so no real H1) is not meaningful content either — every
     * template manifest allows 'hero' on every page type it declares.
     */
    public function test_a_page_with_sections_but_no_hero_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1]['sections'] = [['type' => 'text', 'data' => ['heading' => null, 'body' => 'Some real body text with no heading at all.']]];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_page_with_two_hero_sections_fails_validation(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[0]['sections'][] = ['type' => 'hero', 'data' => ['heading' => 'Second hero', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    // -----------------------------------------------------------------
    // Malformed provider output — must fail as a normal
    // ValidationException, never an uncaught TypeError/crash.
    // -----------------------------------------------------------------

    public function test_a_non_object_page_entry_fails_cleanly(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1] = 'not an object at all';

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_sections_value_that_is_a_string_instead_of_an_array_fails_cleanly(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1]['sections'] = 'oops, a string';

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_section_entry_that_is_not_an_object_fails_cleanly(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1]['sections'] = ['not a section object', 12345];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }

    public function test_a_page_entry_with_a_non_string_page_key_fails_cleanly(): void
    {
        $plan = $this->plan();
        $output = $this->validOutputFor($plan);
        $output[1]['page_key'] = ['home'];

        $this->expectException(ValidationException::class);
        app(GuidedGenerationOutputValidator::class)->validate($output, $plan);
    }
}
