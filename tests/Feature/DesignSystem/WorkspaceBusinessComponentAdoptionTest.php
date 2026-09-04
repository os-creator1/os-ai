<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A2 (Workspace / Business) — component-adoption
 * assertions. Asserts ONLY the exact locked adoption set from
 * DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md §16: per-file marker
 * counts, and explicit non-adoption proof for every §17 carve-out
 * (repeated-field-name controls, the native "Businesses" card ancestor,
 * the native admin Business-edit title+subtitle header, native textarea/
 * checkbox, and zero adoption inside the two excluded billing/entitlement
 * regions). Never a generic "every X adopts" assumption.
 */
class WorkspaceBusinessComponentAdoptionTest extends TestCase
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

    // -----------------------------------------------------------------
    // Exact total marker counts (mechanically verified)
    // -----------------------------------------------------------------

    public function test_exact_total_marker_counts(): void
    {
        $expected = [
            '<x-card' => 10,
            '<x-alert' => 10,
            '<x-badge' => 12,
            '<x-button' => 15,
            '<x-input' => 34,
            '<x-select' => 8,
            '<x-table' => 8,
            '<x-empty-state' => 6,
            '<x-pagination' => 2,
        ];

        foreach ($expected as $marker => $count) {
            $this->assertMarkerTotal($marker, $count);
        }
    }

    public function test_exact_per_file_marker_breakdown(): void
    {
        $expected = [
            'resources/views/customer/workspaces/index.blade.php' => [
                '<x-card' => 1, '<x-alert' => 3, '<x-badge' => 2, '<x-button' => 1,
                '<x-input' => 1, '<x-select' => 0, '<x-table' => 1, '<x-empty-state' => 0, '<x-pagination' => 0,
            ],
            'resources/views/customer/workspaces/show.blade.php' => [
                '<x-card' => 2, '<x-alert' => 3, '<x-badge' => 4, '<x-button' => 6,
                '<x-input' => 9, '<x-select' => 2, '<x-table' => 3, '<x-empty-state' => 2, '<x-pagination' => 0,
            ],
            'resources/views/customer/business/edit.blade.php' => [
                '<x-card' => 1, '<x-alert' => 2, '<x-badge' => 0, '<x-button' => 1,
                '<x-input' => 11, '<x-select' => 1, '<x-table' => 0, '<x-empty-state' => 0, '<x-pagination' => 0,
            ],
            'resources/views/admin/workspaces/index.blade.php' => [
                '<x-card' => 1, '<x-alert' => 0, '<x-badge' => 2, '<x-button' => 1,
                '<x-input' => 1, '<x-select' => 1, '<x-table' => 1, '<x-empty-state' => 1, '<x-pagination' => 1,
            ],
            'resources/views/admin/workspaces/show.blade.php' => [
                '<x-card' => 3, '<x-alert' => 0, '<x-badge' => 4, '<x-button' => 0,
                '<x-input' => 0, '<x-select' => 0, '<x-table' => 2, '<x-empty-state' => 2, '<x-pagination' => 0,
            ],
            'resources/views/admin/businesses/index.blade.php' => [
                '<x-card' => 1, '<x-alert' => 0, '<x-badge' => 0, '<x-button' => 1,
                '<x-input' => 1, '<x-select' => 2, '<x-table' => 1, '<x-empty-state' => 1, '<x-pagination' => 1,
            ],
            'resources/views/admin/businesses/show.blade.php' => [
                '<x-card' => 1, '<x-alert' => 1, '<x-badge' => 0, '<x-button' => 3,
                '<x-input' => 0, '<x-select' => 1, '<x-table' => 0, '<x-empty-state' => 0, '<x-pagination' => 0,
            ],
            'resources/views/admin/businesses/edit.blade.php' => [
                '<x-card' => 0, '<x-alert' => 1, '<x-badge' => 0, '<x-button' => 2,
                '<x-input' => 11, '<x-select' => 1, '<x-table' => 0, '<x-empty-state' => 0, '<x-pagination' => 0,
            ],
        ];

        foreach ($expected as $view => $markers) {
            $contents = file_get_contents(base_path($view));
            foreach ($markers as $marker => $count) {
                $this->assertSame($count, substr_count($contents, $marker), "Expected {$count} occurrences of {$marker} in {$view}, found " . substr_count($contents, $marker) . '.');
            }
        }
    }

    // -----------------------------------------------------------------
    // industry_other adoption (Correction Round 2 delta)
    // -----------------------------------------------------------------

    public function test_industry_other_adopts_x_input_wherever_it_is_rendered(): void
    {
        foreach ([
            'resources/views/customer/business/edit.blade.php',
            'resources/views/admin/businesses/edit.blade.php',
            'resources/views/customer/workspaces/show.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertMatchesRegularExpression('/<x-input\s+name="industry_other"/', $contents, "Expected industry_other to adopt x-input in {$view}.");
        }
    }

    // -----------------------------------------------------------------
    // Repeated-field-name carve-out — customer/workspaces/show.blade.php
    // -----------------------------------------------------------------

    public function test_repeated_field_names_remain_native_in_customer_workspaces_show(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));

        // "name": workspace-rename form + business-create form, distinct ids.
        $this->assertStringContainsString('<input type="text" class="form-control" id="workspace-rename" name="name"', $contents);
        $this->assertStringContainsString('<input type="text" class="form-control" id="business-name" name="name"', $contents);
        $this->assertSame(0, preg_match('/<x-input\s+name="name"/', $contents), 'The repeated "name" field must never adopt x-input.');

        // "target_workspace_uid": one native <select> per manageable Business row.
        $this->assertStringContainsString('<select name="target_workspace_uid"', $contents);
        $this->assertSame(0, substr_count($contents, '<x-select name="target_workspace_uid"'));

        // "role": add-member field + per-member-row field.
        $this->assertStringContainsString('id="member-role" name="role"', $contents);
        $this->assertStringContainsString('<select name="role" class="form-control form-control-sm', $contents);
        $this->assertSame(0, preg_match('/<x-select\s+name="role"/', $contents));

        // "business_access_scope": ownership-transfer field + add-member field + per-member-row field.
        $this->assertStringContainsString('id="ownership-transfer-scope" name="business_access_scope"', $contents);
        $this->assertStringContainsString('id="member-scope" name="business_access_scope"', $contents);
        $this->assertStringContainsString('<select name="business_access_scope" class="form-control form-control-sm', $contents);
        $this->assertSame(0, preg_match('/<x-select\s+name="business_access_scope"/', $contents));
    }

    // -----------------------------------------------------------------
    // Native ancestor / structural carve-outs
    // -----------------------------------------------------------------

    public function test_customer_workspaces_index_empty_state_copy_remains_native(): void
    {
        // x-empty-state's title prop is HTML-escaped ({{ $title }}), which
        // would turn the literal apostrophe in "don't" into `&#039;` and
        // break the pre-existing, unmodified WorkspaceSwitcherHttpTest
        // assertion (assertSee(..., false), a raw non-escaped match). This
        // one empty-state copy stays native rather than adopting x-empty-state.
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/index.blade.php'));

        $this->assertStringContainsString('<p class="mb-0">You don\'t have access to any Workspaces yet.</p>', $contents);
        $this->assertSame(0, substr_count($contents, '<x-empty-state'));
    }

    public function test_customer_workspaces_show_businesses_card_ancestor_remains_native(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));

        $this->assertMatchesRegularExpression('/<div class="card">\s*<div class="card-header">\s*<h4 class="card-title">Businesses<\/h4>/', $contents);
    }

    public function test_admin_business_edit_card_header_remains_native_with_owner_subtitle(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/businesses/edit.blade.php'));

        $this->assertMatchesRegularExpression('/<div class="card">\s*<div class="card-header">\s*<h4 class="card-title">Edit \{\{ \$business->name \}\}<\/h4>\s*<p class="text-muted mb-0">Owner:/', $contents);
        $this->assertSame(0, substr_count($contents, '<x-card'));
    }

    public function test_admin_business_show_actions_slot_holds_both_header_buttons(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/businesses/show.blade.php'));

        $this->assertStringContainsString('<x-slot:actions>', $contents);
        $this->assertMatchesRegularExpression('/<x-slot:actions>.*Edit.*Usage Billing.*<\/x-slot:actions>/s', $contents);
    }

    // -----------------------------------------------------------------
    // Excluded regions — zero adoption
    // -----------------------------------------------------------------

    public function test_no_adoption_marker_inside_excluded_billing_entitlement_regions(): void
    {
        $customerContents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));
        $planCapacityRegion = $this->extractBetween($customerContents, '@isset($entitlement)', '@endisset');
        $this->assertNotNull($planCapacityRegion);
        $this->assertSame(0, substr_count($planCapacityRegion, '<x-'));

        $usageBillingRegion = $this->extractBetween($customerContents, 'Usage &amp; Billing</h5>', '@endisset');
        $this->assertNotNull($usageBillingRegion);
        $this->assertSame(0, substr_count($usageBillingRegion, '<x-'));

        $adminContents = file_get_contents(base_path('resources/views/admin/workspaces/show.blade.php'));
        $planEntitlementRegion = $this->extractBetween($adminContents, "@can('view workspace plans')", '@endsection');
        $this->assertNotNull($planEntitlementRegion);
        $this->assertSame(0, substr_count($planEntitlementRegion, '<x-'));
    }

    // -----------------------------------------------------------------
    // Explicit non-adoption assertions
    // -----------------------------------------------------------------

    public function test_no_forbidden_component_is_ever_adopted_anywhere_in_the_allowlist(): void
    {
        foreach (['<x-dialog', '<x-menu', '<x-tooltip', '<x-tabs', '<x-switch-toggle', '<x-textarea', '<x-checkbox', '<x-radio'] as $marker) {
            $this->assertMarkerTotal($marker, 0);
        }
    }

    public function test_native_textarea_and_checkboxes_are_never_wrapped(): void
    {
        $customerEdit = file_get_contents(base_path('resources/views/customer/business/edit.blade.php'));
        $this->assertStringContainsString('<textarea class="form-control" id="description" name="description"', $customerEdit);

        $adminEdit = file_get_contents(base_path('resources/views/admin/businesses/edit.blade.php'));
        $this->assertStringContainsString('<textarea name="description" class="form-control"', $adminEdit);

        $workspaceShow = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));
        $this->assertStringContainsString('<textarea class="form-control" id="business-description" name="description"', $workspaceShow);
        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="checkbox" name="business_uids\[\]"/', $workspaceShow);
    }

    public function test_outline_danger_warning_success_buttons_remain_native(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/workspaces/show.blade.php'));

        foreach ([
            '<button type="submit" class="btn btn-outline-danger">Deactivate Workspace</button>',
            '<button type="submit" class="btn btn-outline-success">Reactivate Workspace</button>',
            '<button type="submit" class="btn btn-outline-warning">Transfer ownership</button>',
            '<button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>',
            '<button type="submit" class="btn btn-sm btn-outline-success">Reactivate</button>',
        ] as $needle) {
            $this->assertStringContainsString($needle, $contents);
        }
    }

    public function test_no_shared_component_source_file_was_modified(): void
    {
        foreach ([
            'resources/views/components/card.blade.php',
            'resources/views/components/alert.blade.php',
            'resources/views/components/badge.blade.php',
            'resources/views/components/button.blade.php',
            'resources/views/components/input.blade.php',
            'resources/views/components/select.blade.php',
            'resources/views/components/table.blade.php',
            'resources/views/components/empty-state.blade.php',
            'resources/views/components/pagination.blade.php',
        ] as $component) {
            $this->assertFileExists(base_path($component));
        }

        $card = file_get_contents(base_path('resources/views/components/card.blade.php'));
        $this->assertStringNotContainsString("'subtitle'", $card, 'x-card must not gain a subtitle prop as part of A2.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertMarkerTotal(string $marker, int $expected): void
    {
        $total = 0;
        foreach (self::A2_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));
            $total += substr_count($contents, $marker);
        }

        $this->assertSame($expected, $total, "Expected exactly {$expected} occurrences of {$marker} across the 8-view allowlist, found {$total}.");
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
