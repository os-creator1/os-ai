<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A1 (Business Onboarding) — mechanical content-hygiene
 * checks across the six-view allowlist of the five-step flow (show + Business,
 * Location, Services, Website, Finish). Proves zero hardcoded color literals,
 * zero data-feather occurrences, no hardcoded font families or icon migration,
 * and the stepper's restyle-only shape: five labels in the canonical order, an
 * .active pill with aria-current, no numbers-as-state/icons/percentage beyond the
 * plain "n." prefix, and links only for steps the customer has already reached.
 * The analysis step's polling script and ARIA status region went with the Analysis
 * step; no polling script remains anywhere in the flow.
 */
class BusinessOnboardingDesignSystemContentTest extends TestCase
{
    private const A1_VIEWS = [
        'resources/views/customer/onboarding/show.blade.php',
        'resources/views/customer/onboarding/steps/business.blade.php',
        'resources/views/customer/onboarding/steps/location.blade.php',
        'resources/views/customer/onboarding/steps/services.blade.php',
        'resources/views/customer/onboarding/steps/website.blade.php',
        'resources/views/customer/onboarding/steps/finish.blade.php',
    ];

    public function test_zero_hardcoded_color_literals_across_all_six_views(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents), "Expected zero hex color literals in {$view}.");
            $this->assertSame(0, preg_match('/\brgba?\([^)]*\)/', $contents), "Expected zero rgb()/rgba() color literals in {$view}.");
        }
    }

    public function test_zero_data_feather_occurrences_across_all_six_views(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'data-feather'), "Expected zero data-feather occurrences in {$view}.");
        }
    }

    public function test_stepper_renders_the_five_canonical_steps_from_the_enum_in_order(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertStringContainsString('@foreach ($steps as $number => $item)', $contents);
        $this->assertStringContainsString('{{ $number + 1 }}. {{ $item->label() }}', $contents);
        // show.blade.php has 2 @foreach loops: the stepper and the $errors->all() loop inside the alert block.
        $this->assertSame(2, substr_count($contents, '@foreach'));

        $this->assertSame(
            ['Business', 'Location', 'Services', 'Website', 'Finish'],
            array_map(fn (\App\Enums\Business\OnboardingStep $step) => $step->label(), \App\Enums\Business\OnboardingStep::current()),
        );
    }

    public function test_stepper_introduces_no_new_visual_state_markers(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        foreach (['<x-stepper', '<x-progress-steps', 'completed_steps', 'step-number', 'checkmark', 'progress-percent', '<button'] as $marker) {
            $this->assertStringNotContainsString($marker, $contents, "Expected {$marker} to never appear in the stepper markup.");
        }
    }

    public function test_only_steps_already_reached_are_links_and_the_current_step_is_a_plain_active_pill(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertStringContainsString('@if ($reachable && $item !== $step)', $contents);
        $this->assertStringContainsString('<span class="nav-link {{ $item === $step ? \'active\' : \'\' }}"', $contents);
    }

    public function test_stepper_active_pill_carries_the_permitted_optional_aria_current(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertStringContainsString('aria-current="step"', $contents);
    }

    public function test_no_polling_script_or_status_region_remains_in_the_flow(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('fetch(', $contents, "{$view} must not poll: no analysis runs during onboarding.");
            $this->assertStringNotContainsString('analysis-status', $contents);
            $this->assertStringNotContainsString('onboarding.analysis', $contents);
        }
    }

    public function test_no_forbidden_icon_migration_marker_was_introduced(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('<x-ds-icon', $contents, "{$view} must not introduce icon migration — 0 data-feather confirmed, out of A1 scope.");
        }

    }

    public function test_no_hardcoded_font_family_name_is_introduced(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            preg_match_all('/font-family\s*:\s*([^;]+);/', $contents, $matches);
            $this->assertSame([], $matches[1], "{$view} must not introduce any font-family declaration.");
        }
    }

    public function test_locked_non_adoptions_stay_native_across_the_allowlist(): void
    {
        $business = file_get_contents(base_path('resources/views/customer/onboarding/steps/business.blade.php'));
        $this->assertStringContainsString('<textarea class="form-control" id="description" name="description"', $business);

        $location = file_get_contents(base_path('resources/views/customer/onboarding/steps/location.blade.php'));
        $this->assertStringContainsString('type="checkbox" id="public_address" name="public_address"', $location);
        $this->assertStringContainsString('type="radio" name="service_mode"', $location);
        $this->assertStringContainsString('<textarea class="form-control" id="service_area_cities"', $location);

        $services = file_get_contents(base_path('resources/views/customer/onboarding/steps/services.blade.php'));
        $this->assertMatchesRegularExpression('/type="checkbox" name="services\[\{\{ \$index \}\}\]\[is_primary\]"/', $services);
        $this->assertStringContainsString('type="checkbox" name="suggested_services[]"', $services);
        $this->assertStringContainsString('type="radio" name="primary_suggested"', $services);

        $website = file_get_contents(base_path('resources/views/customer/onboarding/steps/website.blade.php'));
        $this->assertStringContainsString('type="radio" name="website_choice"', $website);

        $finish = file_get_contents(base_path('resources/views/customer/onboarding/steps/finish.blade.php'));
        $this->assertStringContainsString('<x-button type="submit" variant="primary">Enter MotionGrove</x-button>', $finish);
    }
}
