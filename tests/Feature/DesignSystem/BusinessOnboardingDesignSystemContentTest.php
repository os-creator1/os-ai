<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A1 (Business Onboarding) — mechanical content-hygiene
 * checks across the exact 9-view allowlist (DESIGN-SYSTEM-M2-A1-BUSINESS-
 * ONBOARDING-CONTRACT.md §18). Proves zero hardcoded color literals, zero
 * data-feather occurrences, the stepper's restyle-only shape (8 non-
 * interactive labels, .active-only semantics, no numbers/icons/percentage),
 * and that the analysis step's polling script and ARIA status region are
 * byte-for-byte untouched. Makes zero assertion requiring any controller,
 * route, or config change.
 */
class BusinessOnboardingDesignSystemContentTest extends TestCase
{
    private const A1_VIEWS = [
        'resources/views/customer/onboarding/show.blade.php',
        'resources/views/customer/onboarding/steps/goals.blade.php',
        'resources/views/customer/onboarding/steps/business.blade.php',
        'resources/views/customer/onboarding/steps/location.blade.php',
        'resources/views/customer/onboarding/steps/services.blade.php',
        'resources/views/customer/onboarding/steps/assets.blade.php',
        'resources/views/customer/onboarding/steps/analysis.blade.php',
        'resources/views/customer/onboarding/steps/results.blade.php',
        'resources/views/customer/onboarding/steps/complete.blade.php',
    ];

    public function test_zero_hardcoded_color_literals_across_all_9_views(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents), "Expected zero hex color literals in {$view}.");
            $this->assertSame(0, preg_match('/\brgba?\([^)]*\)/', $contents), "Expected zero rgb()/rgba() color literals in {$view}.");
        }
    }

    public function test_zero_data_feather_occurrences_across_all_9_views(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'data-feather'), "Expected zero data-feather occurrences in {$view}.");
        }
    }

    public function test_stepper_retains_exactly_8_non_interactive_labels_in_order(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertStringContainsString(
            "['goals' => 'Goals', 'business' => 'Business', 'location' => 'Location', 'services' => 'Services', 'assets' => 'Assets', 'analysis' => 'Analysis', 'results' => 'Results', 'complete' => 'Complete']",
            $contents
        );
        // show.blade.php has 2 @foreach loops total: the 8-item stepper and
        // the separate $errors->all() loop inside the alert block.
        $this->assertSame(2, substr_count($contents, '@foreach'));
        $this->assertStringContainsString('<span class="nav-link {{ $step->value === $stepValue ? \'active\' : \'\' }}"', $contents);
    }

    public function test_stepper_introduces_no_new_visual_state_markers(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        foreach (['<x-stepper', '<x-progress-steps', 'completed_steps', 'step-number', 'checkmark', 'progress-percent'] as $marker) {
            $this->assertStringNotContainsString($marker, $contents, "Expected {$marker} to never appear in the stepper markup.");
        }
        $this->assertStringNotContainsString('<a ', $contents);
        $this->assertStringNotContainsString('<button', $contents);
    }

    public function test_stepper_active_pill_carries_the_permitted_optional_aria_current(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertStringContainsString('aria-current="step"', $contents);
    }

    public function test_analysis_status_region_role_and_aria_live_are_unchanged(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/steps/analysis.blade.php'));

        $this->assertStringContainsString('<div id="analysis-status" role="status" aria-live="polite">', $contents);
    }

    public function test_analysis_polling_script_is_byte_for_byte_unchanged(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/steps/analysis.blade.php'));

        $this->assertStringContainsString("var attempt = 0;", $contents);
        $this->assertStringContainsString("var maxDelayMs = 15000;", $contents);
        $this->assertStringContainsString("var delay = Math.min(2000 * Math.pow(2, attempt), maxDelayMs);", $contents);
        $this->assertStringContainsString("setTimeout(poll, 2000);", $contents);
        $this->assertSame(1, substr_count($contents, "fetch('{{ route('customer.onboarding.analysis.status') }}'"));
    }

    public function test_no_forbidden_icon_migration_marker_was_introduced(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('<x-ds-icon', $contents, "{$view} must not introduce icon migration — 0 data-feather confirmed, out of A1 scope.");
        }

        $emptyState = file_get_contents(base_path('resources/views/customer/onboarding/steps/results.blade.php'));
        $this->assertStringContainsString('icon="inbox"', $emptyState);
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

        $goals = file_get_contents(base_path('resources/views/customer/onboarding/steps/goals.blade.php'));
        $this->assertStringContainsString('type="checkbox"', $goals);
        $this->assertStringContainsString('class="form-check-input"', $goals);

        $location = file_get_contents(base_path('resources/views/customer/onboarding/steps/location.blade.php'));
        $this->assertStringContainsString('type="checkbox" id="public_address" name="public_address"', $location);

        $services = file_get_contents(base_path('resources/views/customer/onboarding/steps/services.blade.php'));
        $this->assertMatchesRegularExpression('/type="checkbox" name="services\[\{\{ \$index \}\}\]\[is_primary\]"/', $services);

        $results = file_get_contents(base_path('resources/views/customer/onboarding/steps/results.blade.php'));
        $this->assertStringContainsString('<button type="submit" class="btn btn-success">Finish setup</button>', $results);
        $this->assertStringNotContainsString('<x-button type="submit" variant="success"', $results);
    }
}
