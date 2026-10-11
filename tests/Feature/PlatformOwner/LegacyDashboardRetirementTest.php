<?php

namespace Tests\Feature\PlatformOwner;

use App\Helpers\Helper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The inherited Ultimate SMS admin dashboard (SMS charts + "getting started" gateway checklist) is retired:
 * /admin/dashboard only redirects to the user's current MotionGrove landing page.
 */
class LegacyDashboardRetirementTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private const OBSOLETE_MARKERS = ['Getting Started', 'getting-started', 'Add a Sending Server', 'sending server', 'Ultimate SMS', 'Codeglen', 'LarapexChart'];

    private function staff(array $permissions): User
    {
        $staff = User::create([
            'first_name' => 'Backend', 'last_name' => 'Staff', 'email' => 'staff-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($staff);

        return $staff;
    }

    private function assertNoInheritedDashboard(string $html): void
    {
        foreach (self::OBSOLETE_MARKERS as $marker) {
            $this->assertStringNotContainsStringIgnoringCase($marker, $html, "retired content '{$marker}' is still rendered");
        }
    }

    public function test_the_platform_owner_home_displays_the_new_overview(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace', 'view business', 'view customer']);

        $html = $this->get(route('admin.platform-owner.overview'))->assertOk()->getContent();

        $this->assertStringContainsString('admin-platform-owner-overview', $html);
        $this->assertNoInheritedDashboard($html);
    }

    public function test_old_dashboard_url_redirects_a_platform_owner_to_the_platform_owner_home(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace']);

        $this->get('/' . config('app.admin_path') . '/dashboard')->assertRedirect(route('admin.platform-owner.overview'));
        $this->get(route('admin.home'))->assertRedirect(route('admin.platform-owner.overview'));
        $this->assertSame(route('admin.platform-owner.overview'), Helper::home_route());
    }

    public function test_backend_staff_are_redirected_to_an_authorised_landing_page_never_to_an_owner_route(): void
    {
        $this->staff(['access backend', 'view customer']);

        $this->get(route('admin.home'))->assertRedirect(route('admin.staff-home'));
        $page = $this->get(route('admin.staff-home'))->assertOk()->getContent();

        $this->assertStringContainsString('admin-staff-home', $page);
        $this->assertNoInheritedDashboard($page);
        $this->assertStringNotContainsString('platform-owner', $page);   // no owner-only destination offered
        $this->assertSame(route('admin.staff-home'), Helper::home_route());
        $this->get(route('admin.platform-owner.overview'))->assertStatus(401);   // and the owner route stays refused
    }

    public function test_an_administrator_without_the_workspace_permission_lands_on_the_staff_page_not_a_refused_route(): void
    {
        $admin = $this->actingAsPlatformOwner(['access backend', 'view customer']);   // is_admin but no `view workspace`

        $this->get(route('admin.home'))->assertRedirect(route('admin.staff-home'));
        $this->get(route('admin.staff-home'))->assertOk();
        $this->assertNotNull($admin);
    }

    public function test_the_staff_page_is_closed_to_customers_and_guests(): void
    {
        foreach ([route('admin.staff-home'), route('admin.home')] as $url) {
            $guest = $this->get($url);
            $this->assertContains($guest->getStatusCode(), [302, 401], $url);
            $this->assertStringNotContainsString('admin-staff-home', (string) $guest->getContent());
        }

        $customer = User::create([
            'first_name' => 'Plain', 'last_name' => 'Customer', 'email' => 'cust-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer', 'email_verified_at' => now(),
        ]);
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer);

        foreach ([route('admin.home'), route('admin.staff-home'), route('admin.platform-owner.overview')] as $url) {
            $this->assertNotSame(200, $this->get($url)->getStatusCode(), $url);
        }
    }

    public function test_no_route_renders_the_getting_started_checklist_any_more(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\View::exists('admin.dashboard'), 'the inherited dashboard view must be gone');
        $this->assertFileDoesNotExist(resource_path('views/admin/dashboard.blade.php'));
        $this->assertNull(collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_contains((string) $r->getActionName(), 'AdminBaseController@index')));
    }

    public function test_admin_breadcrumbs_point_to_the_current_home_not_the_retired_dashboard(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace', 'view business', 'view customer', 'general settings', 'view audit logs']);
        $legacy = url(config('app.admin_path') . '/dashboard');

        foreach ([route('admin.platform-owner.overview'), route('admin.platform-owner.audit'), route('admin.settings.general')] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('href="' . $legacy . '"', $html, "{$url} still links to the retired dashboard");
        }

        $crumbs = $this->get(route('admin.platform-owner.overview'))->getContent();
        $this->assertStringContainsString(route('admin.platform-owner.overview'), $crumbs);
    }

    public function test_no_admin_source_file_links_to_the_retired_dashboard_url(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers/Admin'))) as $file) {
            if ($file->isFile() && preg_match("#admin_path'\)\s*\.\s*['\"]/dashboard#", (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }
}
