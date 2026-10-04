<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Helpers\Helper;
use App\Library\Navigation\AdminMenuBuilder;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner shell V1 — the Platform Owner sidebar is a coherent,
 * grouped product navigation: it links every real Platform Owner surface,
 * leaks no customer Business navigation, marks exactly one correct entry
 * active, and is never shown to a user who is not a Platform Owner.
 *
 * The grouping/order contract lives in Helper::menuData()['admin'] and is
 * resolved per user by App\Library\Navigation\AdminMenuBuilder.
 */
class PlatformOwnerShellNavigationTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    /** Owner-visible destinations, in the order the sidebar presents them. */
    private const OWNER_ORDER = [
        'Home' => 'platform-owner',
        'Workspaces' => 'workspaces',
        'Businesses' => 'businesses',
        'Opportunities' => 'opportunities',
        'Customers' => 'customers',
        'Subscriptions' => 'subscriptions',
        'Announcements' => 'announcements',
        'Niche Blueprints' => 'niche-blueprints',
        'Template Library' => 'template-library',
        'Messaging Dashboard' => 'dashboard',
        'Sending Servers' => 'sending-servers',
        'Messaging Provisioning Incidents' => 'messaging-provisioning-incidents',
        'Messaging Port-Out Requests' => 'messaging-port-out-requests',
        'Messaging Number Lifecycle' => 'messaging-number-lifecycle',
        'Billing & Revenue' => 'platform-billing',
        'Plan Catalog' => 'workspace-plan-catalog',
        'Plans' => 'plans',
        'Invoices' => 'invoices',
        'Safety Limits' => 'usage-billing/safety-limits',
        'AI Usage' => 'ai-usage',
        'Provider Events' => 'provider-events',
        'Additional Slot Agreements' => 'additional-business-slot-agreements',
        'Audit Logs' => 'platform-owner/audit',
        'Platform Settings' => 'settings',
    ];

    /** The Platform Owner product surfaces: each must render, not merely exist. */
    private const OWNER_SURFACES = [
        'Home', 'Workspaces', 'Businesses', 'Opportunities', 'Niche Blueprints', 'Template Library',
        'Messaging Provisioning Incidents', 'Messaging Port-Out Requests', 'Messaging Number Lifecycle',
        'Billing & Revenue', 'Plan Catalog', 'Safety Limits', 'AI Usage', 'Provider Events',
        'Additional Slot Agreements', 'Audit Logs',
    ];

    private const OWNER_HEADERS = [
        'Accounts & Operations',
        'Product & Configuration',
        'Messaging & Infrastructure',
        'Commercial',
        'Governance',
        'System',
    ];

    /** Every permission string the owner menu can ask for. */
    private const ALL_OWNER_PERMISSIONS = [
        'access backend', 'view workspace', 'view business', 'edit business', 'view workspace plans',
        'manage workspace plans', 'edit customer', 'view opportunities', 'view customer', 'view subscription',
        'view announcement', 'manage plans', 'manage currencies', 'manage tax', 'view sender_id', 'view keywords',
        'view sending_servers', 'view phone_numbers', 'view tags', 'view templates', 'view blacklist',
        'view spam_word', 'view blocked_senderids', 'view block_senderid', 'view administrator', 'view roles',
        'general settings', 'authentication settings', 'system_email settings', 'manage ai_settings',
        'view languages', 'view email_templates', 'manage maintenance_mode', 'manage theme', 'view sms_history',
        'view invoices',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();

        // These tests pin the full inherited sidebar; the default-off behaviour is
        // covered by test_legacy_messaging_entries_are_hidden_by_default below.
        config(['app.legacy_messaging_menu' => true]);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array{links: array<int, array{label: string, href: string, active: bool}>, headers: array<int, string>}
     */
    private function sidebar(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        $links = [];
        foreach ($xpath->query('//ul[@id="main-menu-navigation"]//li[a[@href]]') as $li) {
            $a = $xpath->query('a[@href]', $li)->item(0);
            $label = trim((string) $xpath->query('.//span[contains(@class,"menu-title") or contains(@class,"menu-item")]', $a)->item(0)?->textContent);
            $links[] = [
                'label' => $label,
                'href' => $a->getAttribute('href'),
                'active' => str_contains(' ' . $li->getAttribute('class') . ' ', ' active '),
            ];
        }

        $headers = [];
        foreach ($xpath->query('//ul[@id="main-menu-navigation"]//li[contains(@class,"navigation-header")]/span') as $span) {
            $headers[] = trim($span->textContent);
        }

        return ['links' => $links, 'headers' => $headers];
    }

    private function activeLabels(array $sidebar): array
    {
        return array_values(array_map(fn ($l) => $l['label'], array_filter($sidebar['links'], fn ($l) => $l['active'])));
    }

    private function actingAsFullOwner(): User
    {
        config(['opportunity.enabled' => true]);
        // The Usage gateway refuses to boot without Stripe credentials in a bare test env
        // (a known local-env trait). Nothing here calls Stripe, so bind an inert double.
        $this->app->instance(\App\Library\Usage\Contracts\PaymentProviderGateway::class, new \App\Library\Usage\FakePaymentProviderGateway());

        return $this->actingAsPlatformOwner(self::ALL_OWNER_PERMISSIONS);
    }

    // --- the sidebar contract --------------------------------------------

    public function test_owner_sidebar_lists_the_real_destinations_grouped_and_in_order(): void
    {
        $this->actingAsFullOwner();

        $sidebar = $this->sidebar($this->get(route('admin.platform-owner.overview'))->assertOk()->getContent());

        $this->assertSame(self::OWNER_HEADERS, $sidebar['headers']);

        $labels = array_column($sidebar['links'], 'label');
        $positions = [];
        foreach (array_keys(self::OWNER_ORDER) as $label) {
            $this->assertContains($label, $labels, "Owner sidebar is missing {$label}");
            $positions[] = array_search($label, $labels, true);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Owner sidebar destinations are out of the documented order.');

        $adminBase = url(config('app.admin_path'));
        $byLabel = array_column($sidebar['links'], 'href', 'label');
        foreach (self::OWNER_ORDER as $label => $suffix) {
            $this->assertSame($adminBase . '/' . $suffix, $byLabel[$label], "{$label} links to the wrong place");
        }
    }

    public function test_legacy_messaging_entries_are_hidden_by_default_and_the_owner_lands_on_platform_home(): void
    {
        config(['app.legacy_messaging_menu' => false]);
        $this->actingAsFullOwner();

        $labels = array_column($this->sidebar($this->get(route('admin.platform-owner.overview'))->assertOk()->getContent())['links'], 'label');

        foreach (['Messaging Dashboard', 'Sending Servers', 'Sender ID', 'SMS History', 'Plans', 'Invoices', 'Blacklist', 'Customers', 'Subscriptions'] as $legacy) {
            $this->assertNotContains($legacy, $labels, "{$legacy} is a legacy gateway surface and must not be in the default Platform Owner sidebar.");
        }
        foreach (['Home', 'Workspaces', 'Announcements', 'Plan Catalog', 'Administrators', 'Number Operations'] as $kept) {
            $this->assertContains($kept, $labels, "{$kept} must stay in the Platform Owner sidebar.");
        }

        $this->assertSame(route('admin.platform-owner.overview'), \App\Helpers\Helper::home_route());
    }

    public function test_previously_unlinked_owner_surfaces_are_now_reachable_from_the_sidebar(): void
    {
        $this->actingAsFullOwner();

        $hrefs = array_column(
            $this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent())['links'],
            'href'
        );

        foreach (['admin.platform-billing.index', 'admin.workspace-plan-catalog.index', 'admin.opportunities.index', 'admin.platform-owner.audit'] as $route) {
            $this->assertContains(route($route), $hrefs, "{$route} is routed but not linked");
        }
    }

    public function test_every_owner_sidebar_link_opens_and_none_is_dead(): void
    {
        $this->actingAsFullOwner();
        $sidebar = $this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent());

        foreach ($sidebar['links'] as $link) {
            if ($link['href'] === '' || str_starts_with($link['href'], 'javascript')) {
                continue; // a collapsible group header, not a destination
            }

            $status = $this->get($link['href'])->getStatusCode();

            if (in_array($link['label'], self::OWNER_SURFACES, true)) {
                $this->assertSame(200, $status, "{$link['label']} ({$link['href']}) answered {$status}");

                continue;
            }

            // Legacy Ultimate SMS pages: some need seeded app_config rows the bare test DB lacks
            // (a 500 there is environmental), but a dead or unauthorized link is never acceptable.
            $this->assertNotContains($status, [401, 403, 404, 405], "{$link['label']} ({$link['href']}) answered {$status}");
        }
    }

    public function test_no_customer_business_navigation_leaks_into_the_owner_shell(): void
    {
        $this->actingAsFullOwner();
        $sidebar = $this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent());

        $adminBase = url(config('app.admin_path'));
        foreach ($sidebar['links'] as $link) {
            if ($link['href'] === '' || str_starts_with($link['href'], 'javascript')) {
                continue;
            }
            $this->assertStringStartsWith($adminBase . '/', $link['href'], "{$link['label']} points outside the admin portal");
        }

        $labels = array_column($sidebar['links'], 'label');
        foreach (['Contacts', 'Conversations', 'Website', 'Get found', 'Client accounts', 'Switch'] as $customerLabel) {
            $this->assertNotContains($customerLabel, $labels);
        }
        $this->assertSame([], $this->customerShellMarkers($this->get(route('admin.platform-owner.overview'))->getContent()));
    }

    /** @return array<int, string> */
    private function customerShellMarkers(string $html): array
    {
        return array_values(array_filter(
            ['customer-context-current', 'Business navigation', 'Account navigation'],
            fn (string $marker) => str_contains($html, $marker)
        ));
    }

    // --- active state -----------------------------------------------------

    public function test_exactly_one_correct_entry_is_active_per_page(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsFullOwner();

        $cases = [
            [route('admin.platform-owner.overview'), 'Home'],
            [route('admin.platform-owner.audit'), 'Audit Logs'],
            [route('admin.workspaces.index'), 'Workspaces'],
            [route('admin.workspaces.show', $workspace), 'Workspaces'],
            [route('admin.businesses.show', $business), 'Businesses'],
            [route('admin.platform-billing.index'), 'Billing & Revenue'],
            [route('admin.workspace-plan-catalog.index'), 'Plan Catalog'],
            [route('admin.niche-blueprints.index'), 'Niche Blueprints'],
            [route('admin.template-library.index'), 'Template Library'],
            [route('admin.home'), 'Messaging Dashboard'],
        ];

        foreach ($cases as [$url, $expected]) {
            $active = $this->activeLabels($this->sidebar($this->get($url)->assertOk()->getContent()));
            $this->assertSame([$expected], $active, "On {$url}");
        }
    }

    public function test_workspace_plan_catalog_does_not_light_up_workspaces_and_reports_dashboard_does_not_light_up_home(): void
    {
        $this->actingAsFullOwner();
        $builder = app(AdminMenuBuilder::class);
        $owner = auth()->user();

        $activeFor = function (string $path) use ($builder, $owner): array {
            $names = [];
            $walk = function (array $entries) use (&$walk, &$names): void {
                foreach ($entries as $e) {
                    if (! empty($e->active)) {
                        $names[] = $e->name;
                    }
                    if (isset($e->submenu)) {
                        $walk($e->submenu);
                    }
                }
            };
            $walk($builder->build($owner, $path));

            return $names;
        };

        $admin = config('app.admin_path');
        $this->assertSame(['Plan Catalog'], $activeFor("{$admin}/workspace-plan-catalog"));
        $this->assertSame(['Audit Logs'], $activeFor("{$admin}/platform-owner/audit"));
        $this->assertSame(['Home'], $activeFor("{$admin}/platform-owner"));
        // Reports > Dashboard must light up only itself, never the owner's Messaging Dashboard (/dashboard).
        $this->assertSame(['Dashboard'], $activeFor("{$admin}/reports/dashboard"));
        $this->assertSame(['Messaging Dashboard'], $activeFor("{$admin}/dashboard"));
    }

    // --- authorization / isolation ---------------------------------------

    public function test_opportunities_is_not_linked_while_its_module_is_switched_off(): void
    {
        $this->actingAsFullOwner();
        config(['opportunity.enabled' => false]);

        $labels = array_column($this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent())['links'], 'label');

        $this->assertNotContains('Opportunities', $labels);
        $this->get(route('admin.opportunities.index'))->assertNotFound();
    }

    public function test_owner_without_a_permission_is_not_shown_the_link_it_cannot_open(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace']);

        $labels = array_column($this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent())['links'], 'label');

        $this->assertContains('Home', $labels);
        $this->assertContains('Workspaces', $labels);
        $this->assertNotContains('Businesses', $labels);
        $this->assertNotContains('Plan Catalog', $labels);
        $this->assertNotContains('Opportunities', $labels);
    }

    public function test_a_backend_staff_account_that_is_not_a_platform_owner_gets_no_owner_navigation(): void
    {
        $staff = User::create([
            'first_name' => 'Backend',
            'last_name' => 'Staff',
            'email' => 'staff-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        // Every permission the owner shell would ask for — account type, not
        // the permission string, is what must keep the owner entries away.
        $this->withSession(['permissions' => collect(self::ALL_OWNER_PERMISSIONS)]);
        $this->actingAs($staff);

        $sidebar = $this->sidebar($this->get(route('admin.home'))->assertOk()->getContent());
        $labels = array_column($sidebar['links'], 'label');
        $hrefs = array_column($sidebar['links'], 'href');

        $this->assertContains('Dashboard', $labels, 'Staff keep the legacy Dashboard');
        foreach (['Home', 'Workspaces', 'Businesses', 'Opportunities', 'Niche Blueprints', 'Template Library', 'Billing & Revenue', 'Plan Catalog', 'Audit Logs', 'Number Operations', 'Messaging Dashboard'] as $ownerOnly) {
            $this->assertNotContains($ownerOnly, $labels);
        }
        foreach (['platform-owner', 'platform-billing', 'workspace-plan-catalog', 'niche-blueprints', 'template-library', 'messaging-provisioning-incidents', '/workspaces', '/businesses'] as $fragment) {
            foreach ($hrefs as $href) {
                $this->assertStringNotContainsString($fragment, $href);
            }
        }
        $this->assertSame([], array_values(array_filter($sidebar['headers'], fn ($h) => in_array($h, ['Accounts & Operations', 'Product & Configuration', 'Governance'], true))));
    }

    public function test_owner_does_not_see_the_staff_only_dashboard_entry_beside_home(): void
    {
        $this->actingAsFullOwner();

        $links = $this->sidebar($this->get(route('admin.platform-owner.overview'))->getContent())['links'];

        // Reports > Dashboard (admin/reports/dashboard) is a different page; the
        // staff-only entry is the one linking to admin/dashboard labelled Dashboard.
        $staffDashboard = array_filter($links, fn ($l) => $l['label'] === 'Dashboard' && $l['href'] === url(config('app.admin_path') . '/dashboard'));
        $this->assertSame([], array_values($staffDashboard));
        $this->assertContains('Home', array_column($links, 'label'));
    }

    public function test_customer_workspace_owner_and_agency_owner_never_receive_owner_navigation(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);
        $html = $this->get(route('user.home'))->getContent();
        foreach (['platform-owner', 'platform-billing', 'niche-blueprints', 'workspace-plan-catalog'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }

        $fixture = $this->createAgencyManagedClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $html = $this->get(route('user.home'))->getContent();
        foreach (['platform-owner', 'platform-billing', 'niche-blueprints', 'workspace-plan-catalog'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }

    public function test_builder_returns_nothing_without_a_user(): void
    {
        $this->assertSame([], app(AdminMenuBuilder::class)->build(null, 'admin/platform-owner'));
    }

    // --- support routes stay reachable -----------------------------------

    public function test_support_routes_remain_reachable_from_the_owner_shell(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsFullOwner();

        $home = $this->get(route('admin.platform-owner.overview'))->assertOk();
        $home->assertSee(route('admin.workspaces.index'), false);
        $home->assertSee(route('admin.platform-billing.index'), false);
        $home->assertSee(route('admin.platform-owner.audit'), false);
        $home->assertSee('Recent admin activity');

        $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $this->get(route('admin.businesses.show', $business))->assertOk();
    }

    // --- data-level grouping contract ------------------------------------

    public function test_menu_data_declares_the_documented_headers_and_no_legacy_platform_owner_group(): void
    {
        $admin = Helper::menuData()['admin'];

        $headers = array_values(array_map(fn ($e) => $e['navheader'], array_filter($admin, fn ($e) => isset($e['navheader']))));
        $this->assertSame(self::OWNER_HEADERS, $headers);

        $topNames = array_column(array_filter($admin, fn ($e) => isset($e['name'])), 'name');
        $this->assertNotContains('Platform Owner', $topNames, 'Home/Workspaces/Businesses/Audit Logs are top-level, not a nested group');

        // The three messaging ops screens were moved out of Usage Billing, not duplicated.
        $usage = collect($admin)->firstWhere('name', 'Usage Billing');
        $this->assertNotContains('Messaging Provisioning Incidents', array_column($usage['submenu'], 'name'));
        $ops = collect($admin)->firstWhere('name', 'Number Operations');
        $this->assertSame(
            ['Messaging Provisioning Incidents', 'Messaging Port-Out Requests', 'Messaging Number Lifecycle'],
            array_column($ops['submenu'], 'name')
        );

        // No destination is listed twice under different labels.
        $urls = [];
        $walk = function (array $entries) use (&$walk, &$urls): void {
            foreach ($entries as $e) {
                if (! empty($e['url'])) {
                    $urls[] = $e['url'];
                }
                if (isset($e['submenu'])) {
                    $walk($e['submenu']);
                }
            }
        };
        $walk($admin);
        $dupes = array_keys(array_filter(array_count_values($urls), fn ($n) => $n > 1));
        $this->assertSame([url(config('app.admin_path') . '/dashboard')], $dupes, 'Only the legacy dashboard is deliberately offered twice (staff vs owner).');
    }
}
