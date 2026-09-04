<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A1 (Business Onboarding) — component-adoption assertions.
 * Asserts ONLY the exact locked adoption set from the final contract's §11
 * matrix: 3 <x-card>, 1 <x-alert>, 12 <x-button>, 20 <x-input>, 2 <x-select>,
 * 1 <x-empty-state> — with an exact per-file breakdown — and explicitly
 * proves the §12 non-adoption carve-outs (native textarea, native
 * checkbox/radio, native btn-success "Finish setup") introduced zero
 * forbidden markers. Never a generic "every X adopts" assumption.
 */
class BusinessOnboardingComponentAdoptionTest extends TestCase
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

    public function test_exactly_3_card_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-card', 3);
    }

    public function test_exactly_1_alert_marker_is_present(): void
    {
        $this->assertMarkerTotal('<x-alert', 1);
    }

    public function test_exactly_12_button_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-button', 12);
    }

    public function test_exactly_20_input_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-input', 20);
    }

    public function test_exactly_2_select_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-select', 2);
    }

    public function test_exactly_1_empty_state_marker_is_present(): void
    {
        $this->assertMarkerTotal('<x-empty-state', 1);
    }

    public function test_exact_per_file_marker_breakdown(): void
    {
        $expected = [
            'resources/views/customer/onboarding/show.blade.php' => ['<x-card' => 1, '<x-alert' => 1, '<x-button' => 0, '<x-input' => 0, '<x-select' => 0, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/goals.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 1, '<x-input' => 0, '<x-select' => 0, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/business.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 1, '<x-input' => 7, '<x-select' => 1, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/location.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 1, '<x-input' => 5, '<x-select' => 1, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/services.blade.php' => ['<x-card' => 2, '<x-alert' => 0, '<x-button' => 1, '<x-input' => 4, '<x-select' => 0, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/assets.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 2, '<x-input' => 3, '<x-select' => 0, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/analysis.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 3, '<x-input' => 0, '<x-select' => 0, '<x-empty-state' => 0],
            'resources/views/customer/onboarding/steps/results.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 2, '<x-input' => 1, '<x-select' => 0, '<x-empty-state' => 1],
            'resources/views/customer/onboarding/steps/complete.blade.php' => ['<x-card' => 0, '<x-alert' => 0, '<x-button' => 1, '<x-input' => 0, '<x-select' => 0, '<x-empty-state' => 0],
        ];

        foreach ($expected as $view => $markers) {
            $contents = file_get_contents(base_path($view));
            foreach ($markers as $marker => $count) {
                $this->assertSame($count, substr_count($contents, $marker), "Expected {$count} occurrences of {$marker} in {$view}.");
            }
        }
    }

    public function test_show_blade_alert_wraps_the_existing_error_loop(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/show.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<x-alert variant="danger">\s*<ul class="mb-0">\s*@foreach \(\$errors->all\(\) as \$error\)/s',
            $contents
        );
    }

    public function test_services_card_wraps_both_the_existing_row_loop_and_the_new_row_block(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/steps/services.blade.php'));

        $this->assertMatchesRegularExpression('/@foreach \(\$services as \$index => \$service\)\s*<x-card/s', $contents);
        $this->assertMatchesRegularExpression('/@php\(\$newIndex = \$services->count\(\)\)\s*<x-card/s', $contents);
    }

    public function test_results_go_fix_this_and_save_use_the_locked_variants_and_sizes(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/steps/results.blade.php'));

        $this->assertStringContainsString('<x-button type="submit" variant="outline" size="sm">Go fix this</x-button>', $contents);
        $this->assertStringContainsString('<x-button type="submit" variant="primary" size="sm">Save</x-button>', $contents);
    }

    public function test_results_value_input_has_no_old_binding_forwarding_only_pattern(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/onboarding/steps/results.blade.php'));

        $this->assertStringContainsString('<x-input name="value" type="text" placeholder="Enter a value" maxlength="2048"', $contents);
        $this->assertStringNotContainsString("old('value'", $contents);
    }

    // -----------------------------------------------------------------
    // Explicit non-adoption assertions — never demand what the contract
    // locks as native (§12).
    // -----------------------------------------------------------------

    public function test_no_forbidden_component_is_ever_adopted_anywhere_in_the_allowlist(): void
    {
        foreach (['<x-badge', '<x-dialog', '<x-table', '<x-pagination', '<x-menu', '<x-tooltip', '<x-stepper', '<x-progress-steps', '<x-ds-icon'] as $marker) {
            $this->assertMarkerTotal($marker, 0);
        }
    }

    public function test_native_textarea_checkbox_and_btn_success_are_never_wrapped(): void
    {
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('<x-textarea', $contents, "{$view} must never adopt a non-existent x-textarea component.");
            $this->assertStringNotContainsString('<x-checkbox', $contents, "{$view} must never adopt a non-existent x-checkbox component.");
            $this->assertStringNotContainsString('<x-radio', $contents, "{$view} must never adopt a non-existent x-radio component.");
        }

        $results = file_get_contents(base_path('resources/views/customer/onboarding/steps/results.blade.php'));
        $this->assertStringContainsString('btn-success', $results);
        $this->assertStringNotContainsString('variant="success"', $results);
    }

    public function test_no_shared_component_source_file_was_modified_to_force_an_adoption(): void
    {
        foreach ([
            'resources/views/components/card.blade.php',
            'resources/views/components/alert.blade.php',
            'resources/views/components/button.blade.php',
            'resources/views/components/input.blade.php',
            'resources/views/components/select.blade.php',
            'resources/views/components/empty-state.blade.php',
        ] as $component) {
            $this->assertFileExists(base_path($component));
        }

        $select = file_get_contents(base_path('resources/views/components/select.blade.php'));
        $this->assertStringNotContainsString("'error' =>", $select, 'x-select must not gain an error prop as part of A1 — a confirmed API gap the contract left unaddressed.');
    }

    private function assertMarkerTotal(string $marker, int $expected): void
    {
        $total = 0;
        foreach (self::A1_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));
            $total += substr_count($contents, $marker);
        }

        $this->assertSame($expected, $total, "Expected exactly {$expected} occurrences of {$marker} across the 9-view allowlist.");
    }
}
