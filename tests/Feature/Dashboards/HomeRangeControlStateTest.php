<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * The Home date-range control: whether the Custom date fields are open is ONE explicit state (data-custom, rendered from the range actually
 * shown), never inferred from a hidden select, and a preset can never produce a second `range` parameter or an empty custom range.
 */
class HomeRangeControlStateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function form(array $query = []): string
    {
        $html = $this->get(route('user.home', $query))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<form[^>]*data-role="analytics-range".*?</form>#s', $html, $m));

        return $m[0];
    }

    private function established(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Range Venue', 'Range Account');
        $this->contactsAdded($business, 2, '2026-09-03');
        $this->authenticateAs($owner);
    }

    public function test_the_home_range_form_has_an_explicit_custom_state_and_no_second_range_parameter(): void
    {
        $this->established();
        $form = $this->form();

        $this->assertStringContainsString('data-segmented="1"', $form);
        $this->assertStringContainsString('data-custom="0"', $form);
        $this->assertStringContainsString('data-preset="this_month"', $form, 'The displayed period is rendered for the control to restore.');

        // The hidden select must not be a successful control: it would add a second `range` beside the pressed preset button.
        $this->assertSame(1, preg_match('#<select[^>]*data-role="range-preset"[^>]*>#', $form, $select));
        $this->assertStringNotContainsString('name="range"', $select[0]);

        // Only the preset buttons (and Apply) carry the name; the date fields are marked so the script can disable them while closed.
        $this->assertSame(3 + 1, preg_match_all('#<button[^>]*name="range"#', $form), 'Three presets and Apply.');
        $this->assertSame(2, substr_count($form, 'data-custom-field'));
        $this->assertMatchesRegularExpression('#data-role="custom-dates"[^>]*\shidden[\s>]#', $form, 'Closed by default.');
    }

    public function test_a_valid_custom_range_opens_the_fields_and_is_the_active_choice(): void
    {
        $this->established();
        $form = $this->form(['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-05']);

        $this->assertStringContainsString('data-custom="1"', $form);
        $this->assertStringContainsString('data-preset="custom"', $form);
        $this->assertDoesNotMatchRegularExpression('#data-role="custom-dates"[^>]*\shidden[\s>]#', $form);
        $this->assertMatchesRegularExpression('#class="is-active"[^>]*data-role="range-custom-toggle"#', $form);
        $this->assertStringContainsString('value="2026-09-01"', $form);
    }

    public function test_an_invalid_range_falls_back_visibly_without_opening_custom_or_mismatching_the_buttons(): void
    {
        $this->established();

        foreach ([
            ['range' => 'custom', 'start' => '', 'end' => ''],
            ['range' => 'custom'],
            ['range' => 'custom', 'start' => '2026-09-10', 'end' => '2026-09-01'],
            ['range' => 'nonsense'],
        ] as $query) {
            $html = $this->get(route('user.home', $query))->assertOk()->getContent();
            $this->assertSame(1, preg_match('#<form[^>]*data-role="analytics-range".*?</form>#s', $html, $m));

            $this->assertStringContainsString('data-custom="0"', $m[0], 'A rejected range never opens the date fields.');
            $this->assertMatchesRegularExpression('#data-role="custom-dates"[^>]*\shidden[\s>]#', $m[0]);
            $this->assertMatchesRegularExpression('#class="is-active"[^>]*aria-pressed="true">This month<#', $m[0], 'The active button is the period actually shown.');
            $this->assertStringContainsString("That date range can't be used", html_entity_decode($html, ENT_QUOTES), 'The fallback is said, not hidden.');
        }

        // A valid request never carries the warning.
        $this->assertStringNotContainsString("That date range can't be used", html_entity_decode($this->get(route('user.home', ['range' => 'last_30_days']))->getContent(), ENT_QUOTES));
    }

    public function test_the_script_decides_custom_from_the_explicit_state_and_blocks_an_empty_custom_range(): void
    {
        $this->established();
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $script = (string) file_get_contents(public_path('js/analytics/range-control.js'));

        $this->assertStringContainsString('js/analytics/range-control.js', $html, 'The shared control script is loaded by the partial.');
        $this->assertStringContainsString("form.getAttribute('data-custom') === '1'", $script, 'Visibility follows data-custom for the segmented control.');
        $this->assertStringContainsString('input.disabled = !custom; input.required = custom;', $script, 'Closed fields add nothing to a URL; open ones are required.');
        $this->assertStringContainsString("win.addEventListener('pageshow'", $script, 'A page restored from history re-syncs from the rendered state.');
    }

    public function test_the_no_javascript_fallback_css_can_never_match_when_javascript_runs(): void
    {
        $this->established();
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        // An async-region swap parses the response with DOMParser (scripting disabled), so a <noscript><style> arrives as a LIVE style element.
        // Unguarded, it showed the date fields and hid the Custom toggle after every in-place update. Every rule must be guarded.
        $this->assertSame(1, preg_match('#<noscript><style>(.*?)</style></noscript>#s', $html, $m));
        $rules = array_filter(array_map('trim', explode('}', $m[1])));
        $this->assertCount(2, $rules);
        foreach ($rules as $rule) {
            $this->assertStringStartsWith('html:not([data-js])', $rule, 'Each fallback rule applies only while JavaScript has not run.');
        }

        // ...and the script that runs with JavaScript marks the page, so those guarded rules cannot match.
        $script = (string) file_get_contents(public_path('js/analytics/range-control.js'));
        $this->assertStringContainsString("setAttribute('data-js', '1')", $script);
        $this->assertStringContainsString("querySelectorAll('noscript')", $script);

        // The script is served with a version query so a deployment cannot leave a stale copy cached.
        $this->assertMatchesRegularExpression('#js/analytics/range-control\.js\?v=\d+#', $html);
    }
    public function test_the_full_size_results_control_is_unchanged(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Results Venue', 'Results Account');
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*name="range"[^>]*data-role="range-preset"#', $html, 'The select is the Results control and keeps its name.');
        $this->assertStringNotContainsString('data-segmented="1"', $html);
    }
}
