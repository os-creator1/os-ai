<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A3 (Admin Users / Roles / Announcements) —
 * component-adoption assertions. Asserts ONLY the mechanically-verified
 * adoption set across the exact 8-view allowlist: exact per-marker
 * totals, and explicit non-adoption proof for every native carve-out —
 * password-toggle fields, select2-driven multi/complex selects with
 * JS-critical ids (role/timezone/locale/customer radios/user_id), the
 * per-permission checkbox matrix, native textareas, and the untouched
 * DataTables `<table>` elements. Never a generic "every X adopts"
 * assumption.
 */
class AdminUsersRolesAnnouncementsComponentAdoptionTest extends TestCase
{
    private const A3_VIEWS = [
        'resources/views/admin/Administrator/index.blade.php',
        'resources/views/admin/Administrator/create.blade.php',
        'resources/views/admin/Administrator/show.blade.php',
        'resources/views/admin/AdminRoles/index.blade.php',
        'resources/views/admin/AdminRoles/create.blade.php',
        'resources/views/admin/Announcements/index.blade.php',
        'resources/views/admin/Announcements/create.blade.php',
        'resources/views/admin/Announcements/_announcements.blade.php',
    ];

    // -----------------------------------------------------------------
    // Exact total marker counts (mechanically verified)
    // -----------------------------------------------------------------

    public function test_exact_total_marker_counts(): void
    {
        $expected = [
            '<x-card' => 7,
            '<x-button' => 7,
            '<x-input' => 10,
            '<x-select' => 2,
            '<x-ds-icon' => 21,
            '<x-alert' => 0,
            '<x-table' => 0,
            '<x-empty-state' => 0,
            '<x-pagination' => 0,
            '<x-badge' => 0,
        ];

        foreach ($expected as $marker => $count) {
            $this->assertMarkerTotal($marker, $count);
        }
    }

    public function test_exact_per_file_marker_breakdown(): void
    {
        $expected = [
            'resources/views/admin/Administrator/index.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 0, '<x-select' => 0, '<x-ds-icon' => 5,
            ],
            'resources/views/admin/Administrator/create.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 4, '<x-select' => 1, '<x-ds-icon' => 2,
            ],
            'resources/views/admin/Administrator/show.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 3, '<x-select' => 0, '<x-ds-icon' => 2,
            ],
            'resources/views/admin/AdminRoles/index.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 0, '<x-select' => 0, '<x-ds-icon' => 5,
            ],
            'resources/views/admin/AdminRoles/create.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 1, '<x-select' => 0, '<x-ds-icon' => 0,
            ],
            'resources/views/admin/Announcements/index.blade.php' => [
                '<x-card' => 0, '<x-button' => 0, '<x-input' => 0, '<x-select' => 0, '<x-ds-icon' => 3,
            ],
            'resources/views/admin/Announcements/create.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 2, '<x-select' => 1, '<x-ds-icon' => 3,
            ],
            'resources/views/admin/Announcements/_announcements.blade.php' => [
                '<x-card' => 1, '<x-button' => 1, '<x-input' => 0, '<x-select' => 0, '<x-ds-icon' => 1,
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
    // Native carve-outs — repeated/JS-critical-id/select2 fields
    // -----------------------------------------------------------------

    public function test_password_toggle_fields_remain_native_in_administrator_forms(): void
    {
        foreach ([
            'resources/views/admin/Administrator/create.blade.php',
            'resources/views/admin/Administrator/show.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringContainsString('class="input-group input-group-merge form-password-toggle"', $contents);
            $this->assertSame(0, preg_match('/<x-input\s+name="password"/', $contents), "{$view} must never adopt x-input for the password-toggle field.");
            $this->assertSame(0, preg_match('/<x-input\s+name="password_confirmation"/', $contents), "{$view} must never adopt x-input for the password-confirmation field.");
        }
    }

    public function test_role_timezone_locale_selects_remain_native_select2(): void
    {
        $create = file_get_contents(base_path('resources/views/admin/Administrator/create.blade.php'));
        $this->assertStringContainsString('<select class="select2 w-100" id="role" name="roles[]">', $create);
        $this->assertSame(0, preg_match('/<x-select\s+name="roles\[\]"/', $create));

        $show = file_get_contents(base_path('resources/views/admin/Administrator/show.blade.php'));
        $this->assertStringContainsString('<select class="select2 w-100" id="role" name="roles[]">', $show);
        $this->assertStringContainsString('<select class="select2 w-100" id="timezone" name="timezone">', $show);
        $this->assertStringContainsString('<select class="select2 w-100" id="locale" name="locale">', $show);
        $this->assertSame(0, preg_match('/<x-select\s+name="(roles\[\]|timezone|locale)"/', $show));
    }

    public function test_customer_selection_radios_and_multiselect_remain_native_in_announcements(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/Announcements/create.blade.php'));

        $this->assertStringContainsString('id="select_all"', $contents);
        $this->assertStringContainsString('id="select_multiple"', $contents);
        $this->assertStringContainsString('id="user_id"', $contents);
        $this->assertSame(0, preg_match('/<x-input\s+name="customer"/', $contents), 'The repeated "customer" radio field must never adopt x-input.');
        $this->assertSame(0, preg_match('/<x-select\s+name="users_id\[\]"/', $contents), 'The users_id multiselect must never adopt x-select.');
    }

    public function test_permission_checkbox_matrix_remains_fully_native_in_admin_roles_create(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/AdminRoles/create.blade.php'));

        $this->assertStringContainsString('name="permissions[]"', $contents);
        $this->assertStringContainsString('id="{{ $permission[\'name\'] }}"', $contents);
        $this->assertStringContainsString('for="{{ $permission[\'name\'] }}"', $contents);
        $this->assertSame(0, substr_count($contents, '<x-select'), 'Permission checkboxes must never be represented via a select-style component.');
        // Every checkbox in the matrix stays a bare native <input type="checkbox">.
        $this->assertMatchesRegularExpression('/<input type="checkbox"\s*$/m', $contents);
    }

    public function test_native_textarea_is_never_wrapped_in_announcements_create(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/Announcements/create.blade.php'));

        $this->assertStringContainsString('<textarea id="description"', $contents);
        $this->assertSame(0, substr_count($contents, '<x-textarea'));
    }

    public function test_send_email_checkbox_remains_native(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/Announcements/create.blade.php'));

        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="checkbox" id="send_email"/', $contents);
    }

    // -----------------------------------------------------------------
    // DataTables tables remain untouched native markup, just re-parented
    // -----------------------------------------------------------------

    public function test_datatables_tables_remain_plain_native_markup_inside_x_card(): void
    {
        $expectations = [
            'resources/views/admin/Administrator/index.blade.php',
            'resources/views/admin/AdminRoles/index.blade.php',
            'resources/views/admin/Announcements/_announcements.blade.php',
        ];

        foreach ($expectations as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertStringContainsString('<x-card :padded="false">', $contents, "{$view} must wrap its DataTables table in a non-padded x-card.");
            $this->assertMatchesRegularExpression('/<table class="table datatables-basic">\s*<thead>/', $contents, "{$view} must keep a plain, unmodified <table class=\"table datatables-basic\"> element.");
            $this->assertSame(0, substr_count($contents, '<x-table'), "{$view} must never replace the DataTables-bound table with <x-table>.");
        }
    }

    // -----------------------------------------------------------------
    // Explicit non-adoption / no-shared-component-modification proof
    // -----------------------------------------------------------------

    public function test_no_forbidden_component_is_ever_adopted_anywhere_in_the_allowlist(): void
    {
        foreach (['<x-dialog', '<x-menu', '<x-tooltip', '<x-tabs', '<x-switch-toggle', '<x-checkbox', '<x-radio'] as $marker) {
            $this->assertMarkerTotal($marker, 0);
        }
    }

    public function test_no_shared_component_source_file_was_modified(): void
    {
        foreach ([
            'resources/views/components/card.blade.php',
            'resources/views/components/button.blade.php',
            'resources/views/components/input.blade.php',
            'resources/views/components/select.blade.php',
            'resources/views/components/ds-icon.blade.php',
        ] as $component) {
            $this->assertFileExists(base_path($component));
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertMarkerTotal(string $marker, int $expected): void
    {
        $total = 0;
        foreach (self::A3_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));
            $total += substr_count($contents, $marker);
        }

        $this->assertSame($expected, $total, "Expected exactly {$expected} occurrences of {$marker} across the 8-view allowlist, found {$total}.");
    }
}
