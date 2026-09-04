<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A2 (Workspace / Business) — mechanical content-hygiene
 * checks across the exact 8-view allowlist locked by
 * DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md §16/§18. Proves zero
 * icon-migration debt, zero hardcoded color/font literals, and that the
 * two embedded billing/entitlement regions (customer/workspaces/show.blade.php's
 * Plan & Capacity + Usage & Billing/Platform feature preferences blocks;
 * admin/workspaces/show.blade.php's Plan & Entitlement + Mutate Plan
 * sections) contain zero Design System adoption markers of any kind, per
 * §5.3/§16/§17's excluded-region rule. Makes zero assertion requiring any
 * controller/route/middleware/manager change.
 */
class WorkspaceBusinessDesignSystemContentTest extends TestCase
{
    private const A2_VIEWS = [
        'resources/views/customer/workspaces/index.blade.php',
        'resources/views/customer/workspaces/show.blade.php',
        'resources/views/customer/business/edit.blade.php',
        'resources/views/admin/workspaces/index.blade.php',
        'resources/views/admin/workspaces/show.blade.php',
        'resources/views/admin/businesses/index.blade.php',
        'resources/views/admin/businesses/show.blade.php',
        'resources/views/admin/businesses/edit.blade.php',
    ];

    public function test_zero_data_feather_occurrences_across_all_8_views(): void
    {
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'data-feather'), "Expected zero data-feather occurrences in {$view}.");
        }
    }

    public function test_zero_hardcoded_color_literals_across_all_8_views(): void
    {
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents), "Expected zero hex color literals in {$view}.");
            $this->assertSame(0, preg_match('/\brgba?\([^)]*\)/', $contents), "Expected zero rgb()/rgba() color literals in {$view}.");
        }
    }

    public function test_no_hardcoded_font_family_declaration_is_introduced(): void
    {
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'font-family'), "Expected zero font-family declarations in {$view}.");
        }
    }

    public function test_no_per_workspace_or_per_business_theme_control_was_introduced(): void
    {
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            foreach (['theme_color', 'brand_color', 'logo_url', 'custom_css', 'theme_preset_override'] as $marker) {
                $this->assertStringNotContainsString($marker, $contents, "{$view} must not introduce a per-Workspace/per-Business theme control.");
            }
        }
    }

    // -----------------------------------------------------------------
    // Excluded billing/entitlement regions — zero adoption of any kind
    // -----------------------------------------------------------------

    public function test_customer_workspaces_show_plan_and_capacity_region_carries_zero_adoption_markers(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));
        $region = $this->extractBetween($contents, '@isset($entitlement)', '@endisset');

        $this->assertNotNull($region, 'Expected to locate the Plan & Capacity excluded region.');
        $this->assertSame(0, substr_count($region, '<x-'), 'Plan & Capacity region must carry zero Design System component markers.');
        $this->assertStringContainsString('id="workspace-plan-capacity"', $region);
        $this->assertStringContainsString('Plan &amp; Capacity', $region);
    }

    public function test_customer_workspaces_show_usage_billing_and_feature_preference_region_carries_zero_adoption_markers(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));
        $region = $this->extractBetween($contents, 'Usage &amp; Billing</h5>', '@endisset');

        $this->assertNotNull($region, 'Expected to locate the Usage & Billing / Platform feature preferences excluded region.');
        $this->assertSame(0, substr_count($region, '<x-'), 'Usage & Billing / feature preference region must carry zero Design System component markers.');
        $this->assertStringContainsString('Platform feature preferences', $region);
        $this->assertStringContainsString('data-business-action="usage-billing"', $region);
    }

    public function test_admin_workspaces_show_plan_entitlement_and_mutate_plan_regions_carry_zero_adoption_markers(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/workspaces/show.blade.php'));
        $region = $this->extractBetween($contents, "@can('view workspace plans')", '@endsection');

        $this->assertNotNull($region, 'Expected to locate the Plan & Entitlement / Mutate Plan excluded regions.');
        $this->assertSame(0, substr_count($region, '<x-'), 'Plan & Entitlement / Mutate Plan regions must carry zero Design System component markers.');
        $this->assertStringContainsString('Plan &amp; Entitlement', $region);
        $this->assertStringContainsString('Mutate Plan', $region);
        $this->assertStringContainsString("@can('manage workspace plans')", $region);
    }

    // -----------------------------------------------------------------
    // Sub-Account / billing scope exclusion
    // -----------------------------------------------------------------

    public function test_no_view_references_sub_account_or_usage_billing_inline(): void
    {
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('SubAccount', $contents, "{$view} must not reference legacy Sub-Accounts.");
            $this->assertSame(0, preg_match('/parent_id/i', $contents), "{$view} must not reference users.parent_id.");
        }
    }

    private function extractBetween(string $haystack, string $start, string $end): ?string
    {
        $startPos = strpos($haystack, $start);

        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($haystack, $end, $startPos);

        if ($endPos === false) {
            return null;
        }

        return substr($haystack, $startPos, $endPos - $startPos);
    }
}
