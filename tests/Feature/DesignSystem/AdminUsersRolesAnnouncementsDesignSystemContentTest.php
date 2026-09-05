<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * Design System M2 A3 (Admin Users / Roles / Announcements) — mechanical
 * content-hygiene checks across the exact 8-view retained allowlist:
 * Administrator (index/create/show), AdminRoles (index/create),
 * Announcements (index/create/_announcements). Proves zero remaining
 * data-feather icon markup, zero hardcoded color/font literals, and that
 * every DataTables-driven index page's JS-critical hooks (element ids,
 * action classes, AJAX-endpoint route names) survived the restyle
 * byte-for-byte. Makes zero assertion requiring any controller/route/
 * permission change.
 */
class AdminUsersRolesAnnouncementsDesignSystemContentTest extends TestCase
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

    public function test_zero_data_feather_occurrences_across_all_8_views(): void
    {
        foreach (self::A3_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'data-feather'), "Expected zero data-feather occurrences in {$view}.");
        }
    }

    public function test_zero_hardcoded_color_literals_across_all_8_views(): void
    {
        foreach (self::A3_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents), "Expected zero hex color literals in {$view}.");
            $this->assertSame(0, preg_match('/\brgba?\([^)]*\)/', $contents), "Expected zero rgb()/rgba() color literals in {$view}.");
        }
    }

    public function test_no_hardcoded_font_family_declaration_is_introduced(): void
    {
        foreach (self::A3_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(0, substr_count($contents, 'font-family'), "Expected zero font-family declarations in {$view}.");
        }
    }

    public function test_no_new_permission_categories_or_deleted_permission_strings_are_reintroduced(): void
    {
        // The Safe Legacy / Dead-Surface Deletion Sweep removed 17 vestigial
        // permission categories (Blogs, FAQs, Testimonials, Widget Builder,
        // Menu Manage, Brands, Support*). None of them belong to Admin
        // Users/Roles/Announcements and none may be reintroduced here.
        $deleted = [
            'view blogs', 'create blog', 'update blog', 'delete blog',
            'view blog_categories', 'view blog_tags', 'manage blog_settings',
            'view faqs', 'create faq', 'update faq', 'delete faq',
            'view faq_categories',
            'view testimonials', 'create testimonial',
            'manage widget_builder',
            'view menu', 'create menu', 'update menu', 'delete menu',
            'view brands', 'create brand',
            'view tickets', 'manage support_settings', 'view support_agents',
        ];

        foreach (self::A3_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            foreach ($deleted as $permission) {
                $this->assertStringNotContainsString($permission, $contents, "{$view} must not reference the deleted permission '{$permission}'.");
            }
        }
    }

    public function test_datatables_ajax_and_jquery_hooks_survive_the_restyle(): void
    {
        // These exact selectors/ids/classes are the load-bearing seam
        // between the Blade markup and each index page's inline
        // page-script DataTables/AJAX wiring. A single renamed id or class
        // silently breaks bulk actions, status toggles, or row deletion.
        $expectations = [
            'resources/views/admin/Administrator/index.blade.php' => [
                'id="bulk_actions"', 'class="dropdown-item bulk-enable"', 'class="dropdown-item bulk-disable"', 'class="dropdown-item bulk-delete"',
                'class="table datatables-basic"', "route('admin.administrators.search')", "route('admin.administrators.batch_action')",
            ],
            'resources/views/admin/AdminRoles/index.blade.php' => [
                'id="bulk_actions"', 'class="dropdown-item bulk-enable"', 'class="dropdown-item bulk-disable"', 'class="dropdown-item bulk-delete"',
                'class="table datatables-basic"', "route('admin.roles.search')", "route('admin.roles.batch_action')",
            ],
            'resources/views/admin/Announcements/_announcements.blade.php' => [
                'id="bulk_actions"', 'class="dropdown-item bulk-delete"', 'class="table datatables-basic"',
            ],
            'resources/views/admin/Announcements/index.blade.php' => [
                "route('admin.announcements.search')", "route('admin.announcements.batch_action')",
                'id="announcements-tab"', 'data-bs-toggle="tab"', 'href="#announcements"',
            ],
        ];

        foreach ($expectations as $view => $needles) {
            $contents = file_get_contents(base_path($view));

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $contents, "Expected {$view} to still contain '{$needle}'.");
            }
        }
    }

    public function test_form_critical_ids_and_classes_survive_the_restyle(): void
    {
        $expectations = [
            'resources/views/admin/Administrator/create.blade.php' => ['id="role"', 'name="roles[]"', 'class="select2 w-100"', 'name="status"'],
            'resources/views/admin/Administrator/show.blade.php' => ['id="role"', 'name="roles[]"', 'id="timezone"', 'id="locale"'],
            'resources/views/admin/AdminRoles/create.blade.php' => ['id="selectAll"', 'name="permissions[]"'],
            'resources/views/admin/Announcements/create.blade.php' => [
                'id="select_all"', 'id="select_multiple"', 'id="user_id"', 'name="users_id[]"',
                'class="form-check-input select_all"', 'class="form-check-input select_multiple"', 'class="form-select users_id"',
            ],
        ];

        foreach ($expectations as $view => $needles) {
            $contents = file_get_contents(base_path($view));

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $contents, "Expected {$view} to still contain '{$needle}'.");
            }
        }
    }
}
