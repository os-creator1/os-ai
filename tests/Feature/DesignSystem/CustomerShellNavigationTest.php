<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — the customer shell as rendered:
 * translation completeness of the touched shell (no raw `locale.` key,
 * contract §17.1), semantic landmarks and active state, an accessible and
 * keyboard-operable switcher, a named mobile menu toggle, icons never the
 * only label, the announced View-as banner, honest empty states, and the
 * source-level design-system discipline of the new partials.
 */
class CustomerShellNavigationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const SLICE_1B_SHELL_SOURCES = [
        'resources/views/panels/sidebar.blade.php',
        'resources/views/panels/navbar.blade.php',
        'resources/views/panels/breadcrumb.blade.php',
        'resources/views/components/customer-nav-item.blade.php',
        'resources/views/components/customer-context-switcher.blade.php',
        'resources/views/components/view-as-banner.blade.php',
    ];

    public function test_no_raw_translation_key_renders_in_the_customer_shell(): void
    {
        [$growth] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($growth);
        $growthHome = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('locale.', $this->shellText($growthHome));
        $this->assertStringNotContainsString('locale.menu.', $growthHome);

        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);
        $agencyHome = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('locale.', $this->shellText($agencyHome));
        $this->assertStringNotContainsString('locale.menu.', $agencyHome);

        // The six labels that used to render as raw keys (E-10) now render as words.
        foreach (['Website', 'Get found'] as $label) {
            $this->assertStringContainsString($label, $this->shellText($growthHome));
        }
        $this->assertStringContainsString('Prospecting', $this->shellText($agencyHome));
    }

    public function test_every_navigation_entry_carries_a_human_label_and_a_hidden_icon(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $sidebar = $this->sidebarHtml($this->home()->assertOk()->getContent());

        preg_match_all('/<li class="[^"]*" data-nav-key="([^"]+)">\s*<a[^>]*>(.*?)<\/a>/s', $sidebar, $entries, PREG_SET_ORDER);
        $this->assertNotEmpty($entries);

        foreach ($entries as [$full, $key, $anchor]) {
            $label = trim(strip_tags($anchor));
            $this->assertNotSame('', $label, "Entry {$key} must carry a text label, not only an icon.");
            $this->assertStringNotContainsString('locale.', $label);
            $this->assertStringContainsString('aria-hidden="true"', $anchor, "The icon of {$key} is decorative.");
        }
    }

    public function test_landmarks_and_active_state_are_exposed_semantically(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $home = $this->home()->assertOk();
        $home->assertSee('role="navigation" aria-label="Business navigation"', false);
        $this->assertSame(1, substr_count($this->sidebarHtml($home->getContent()), 'aria-current="page"'));
        $this->assertSame(['home'], $this->activeMenuKeys($home->getContent()));
        $home->assertSee('aria-expanded="false"', false);

        $analytics = $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
        $this->assertSame(['analytics'], $this->activeMenuKeys($analytics->getContent()));

        $billing = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))->assertOk();
        $this->assertSame(['usage-billing'], $this->activeMenuKeys($billing->getContent()));
        $this->assertMatchesRegularExpression(
            '/<li class="nav-item has-sub open sidebar-group-active" data-nav-key="settings">\s*<a[^>]*aria-expanded="true"/',
            $this->sidebarHtml($billing->getContent()),
            'The Settings group opens and exposes its expanded state for a nested active page.'
        );
    }

    public function test_the_switcher_is_keyboard_operable_and_labelled(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $home = $this->home()->assertOk();
        $shell = $this->shellHtml($home->getContent());

        $this->assertMatchesRegularExpression('/<button[^>]*type="button"[^>]*>/', $shell);
        $this->assertStringContainsString('id="customer-context-switcher-toggle"', $shell);
        $this->assertStringContainsString('aria-haspopup="menu"', $shell);
        $this->assertStringContainsString('aria-expanded="false"', $shell);
        $this->assertStringContainsString('aria-label="Choose a client account"', $shell);
        $this->assertStringContainsString('role="menu"', $shell);
        $this->assertStringContainsString('role="menuitem"', $shell);
        $this->assertStringContainsString('aria-labelledby="customer-context-switcher-toggle"', $shell);
        // No client account is current before an explicit choice. The agency
        // account itself IS current, because that is the frame the actor is
        // standing in (Lane E), so exactly one option carries aria-current and
        // it is the account one.
        $this->assertSame(1, substr_count($shell, 'aria-current="true"'));
        $this->assertStringNotContainsString('Client One', $this->currentOptionMarkup($shell));
        $this->assertStringNotContainsString('Client Two', $this->currentOptionMarkup($shell));
        $this->assertStringContainsString('Northwind Agency', $this->currentOptionMarkup($shell));

        $this->switchTo($workspace, $clientOne);
        $selected = $this->shellHtml($this->home()->assertOk()->getContent());
        $this->assertStringContainsString('aria-label="Current client account: Client One. Switch client account"', $selected);
        $this->assertSame(1, substr_count($selected, 'aria-current="true"'));
        $this->assertStringContainsString('Current', $selected);
    }

    /**
     * The switcher option that carries aria-current, from its <li> to the end
     * of the button that marks it.
     */
    private function currentOptionMarkup(string $shell): string
    {
        $marker = strpos($shell, 'aria-current="true"');
        $this->assertNotFalse($marker, 'An option must be marked current.');

        $start = strrpos(substr($shell, 0, $marker), '<li ');
        $this->assertNotFalse($start, 'The current option must sit in a list item.');

        $end = strpos($shell, '</li>', $marker);

        return substr($shell, $start, $end - $start);
    }

    public function test_a_single_business_owner_gets_a_simple_but_real_switcher(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Solo Business');
        $this->authenticateAs($customer);
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        // Lane E: the block is the control, and it is labelled and operable
        // even with one Business, because the account is also a destination.
        $this->assertStringContainsString('id="customer-context-switcher-toggle"', $shell);
        $this->assertStringContainsString('aria-label="Current business: Solo Business. Switch business"', $shell);
        $this->assertStringContainsString('Solo Business', $shell);

        // Simple, though: one Business row, one account row, no search box.
        $this->assertSame(1, substr_count($shell, 'data-role="context-option-business"'));
        $this->assertSame(1, substr_count($shell, 'data-role="context-option-account"'));
        $this->assertStringNotContainsString('data-role="context-switcher-filter"', $shell);
    }

    public function test_an_actor_with_nothing_to_switch_to_gets_a_labelled_identity(): void
    {
        [$owner, $assigned, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Assigned Client', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Other Client');
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $assigned);
        $this->authenticateAs($staff, ['access_backend', 'view_contact']);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        // A selected-scope member reaches one Business and no account frame:
        // a plain identity is the honest control, not an empty menu.
        $this->assertStringContainsString('data-role="context-identity" aria-label="Current client account"', $shell);
        $this->assertStringNotContainsString('customer-context-switcher-toggle', $shell);
    }

    public function test_mobile_menu_toggles_are_named_and_wired_to_the_navigation(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<a class="nav-link menu-toggle"[^>]*role="button"[^>]*aria-label="Open navigation menu"[^>]*aria-controls="main-menu-navigation"/', $shell);
        $this->assertStringContainsString('aria-label="Collapse or expand the menu"', $shell);
        $this->assertStringContainsString('id="main-menu-navigation"', $shell);
    }

    public function test_the_view_as_banner_is_announced_and_carries_the_exit_control(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Florist');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $home = $this->home()->assertOk();
        $this->assertMatchesRegularExpression('/<div class="[^"]*customer-view-as-banner[^"]*"\s+role="status" aria-live="polite" data-role="view-as-banner">/', $home->getContent());
        $home->assertSee('action="' . route('customer.view-as.exit') . '"', false);
        $home->assertSee('Exit client view', false);
        $home->assertSee('ends automatically at', false);
    }

    public function test_empty_states_explain_the_situation_instead_of_a_bare_surface(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $home = $this->home()->assertOk();
        $shell = $this->shellText($home->getContent());

        $this->assertStringContainsString('No business yet', $shell);
        $this->assertContains('accounts', $this->menuKeys($home->getContent()), 'A way to reach the account surface remains.');
        $this->assertSame(['home'], $this->activeMenuKeys($home->getContent()));
    }

    public function test_shell_sources_stay_on_the_design_system(): void
    {
        foreach (self::SLICE_1B_SHELL_SOURCES as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}\b/', $source, "{$path} must not introduce a hardcoded colour.");
            $this->assertStringNotContainsString('<script src="http', $source, "{$path} must not load a new frontend framework.");
            $this->assertStringNotContainsString('data-feather', $source, "{$path} must render icons through <x-ds-icon>.");
        }

        $sidebar = file_get_contents(base_path('resources/views/panels/sidebar.blade.php'));
        $this->assertStringContainsString('<x-customer-nav-item :item="$item" />', $sidebar);
        $this->assertStringContainsString('$customerShell', $sidebar);
    }

    /**
     * Customer Experience Redesign Slice 1A (Correction 3, contract §6a
     * #13, mechanical render regression required by §9 of the
     * implementation prompt): forcing the dormant horizontal layout on
     * proves the customer branch is genuinely sourced from
     * CustomerMenuBuilder, not the legacy Helper::menuData()['customer']
     * array — a code comment is not sufficient proof.
     */
    public function test_horizontal_layout_customer_branch_renders_from_customer_menu_builder(): void
    {
        config(['app.theme_layout_type' => 'horizontal']);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($customer, $workspace, 'Client Two');
        $this->authenticateAs($customer);

        $response = $this->home()->assertOk();
        $html = $response->getContent();

        // A CustomerMenuBuilder-sourced marker: the exact menu-item keys
        // that builder emits for an Agency owner (§8.3), rendered via
        // data-nav-key exactly as panels/sidebar.blade.php's own items are.
        foreach (['home', 'accounts', 'prospecting', 'settings'] as $expectedKey) {
            $this->assertStringContainsString('data-nav-key="' . $expectedKey . '"', $html, "Horizontal menu must render the CustomerMenuBuilder '{$expectedKey}' entry.");
        }

        // The legacy customer array's own distinctive, never-migrated
        // labels (Helper.php's 'Channels', 'Opportunities', 'Compose') must
        // not appear — proving the old $menuData[1]->customer branch is not
        // what rendered this response.
        $this->assertStringNotContainsString('>Channels<', $html, 'The legacy customer menuData() branch must not render.');
        $this->assertStringNotContainsString('locale.menu.Channels', $html);

        // The admin branch is unchanged: still fed by $menuData[1]->admin,
        // never by CustomerMenuBuilder.
        $admin = User::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'admin-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        $adminResponse = $this->get(route('admin.home'))->assertOk();
        $this->assertStringNotContainsString('data-nav-key="accounts"', $adminResponse->getContent(), 'The admin horizontal branch must not switch to CustomerMenuBuilder.');
    }
}
