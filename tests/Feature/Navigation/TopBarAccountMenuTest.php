<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Branding\BrandingPresenter;
use App\Models\Customer;
use App\Models\Notifications;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The global top bar's notification bell and account menu
 * (components/topbar-notifications, components/topbar-account), rendered by
 * the one shared panels.navbar for every portal.
 *
 *  1. Every role gets the same component: avatar initials and name from the
 *     signed-in person, never fixed text.
 *  2. Settings links only to the destination that role's own sidebar
 *     already offers, and that destination opens; no Settings page, no link.
 *  3. Sign out is a CSRF-protected POST that signs the person out.
 *  4. The bell's unread indicator follows the real unread state.
 *  5. Long names stay one truncated line; the context switcher is untouched.
 */
class TopBarAccountMenuTest extends TestCase
{
    use RefreshDatabase;
    // Brings CreatesCustomerContextFixtures with it (tenants, Agency clients).
    use PlatformOwnerFixtures;

    private const SETTINGS_PERMISSIONS = [
        'general settings', 'authentication settings', 'system_email settings', 'manage ai_settings',
        'view languages', 'view email_templates', 'manage maintenance_mode', 'manage theme',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(BrandingPresenter::CACHE_KEY);
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);

        parent::tearDown();
    }

    // =================================================================
    // 1-2. Platform Owner and administrators
    // =================================================================

    public function test_the_platform_owner_menu_names_them_and_opens_platform_settings(): void
    {
        $owner = $this->actingAsPlatformOwner(array_merge($this->ownerPermissions, self::SETTINGS_PERMISSIONS));
        $owner->forceFill(['first_name' => 'Morgan', 'last_name' => 'Vale'])->save();

        $html = $this->get(route('admin.platform-owner.overview'))->assertOk()->getContent();
        $menu = $this->accountMenu($html);

        $this->assertSame('Morgan Vale', $this->accountName($html));
        $this->assertSame('MV', $this->triggerInitials($html));
        $this->assertStringNotContainsString('Platform Owner', $this->accountRegion($html), 'Never a fixed role label.');

        $settings = url(config('app.admin_path') . '/settings');
        $this->assertSame([route('user.account'), $settings], $this->menuHrefs($menu));
        $this->assertStringNotContainsString('user-menu-product-updates', $menu, 'Product updates is a customer menu entry.');
        $this->assertSignOutIsAPost($menu);

        $this->get($settings)->assertOk();
    }

    public function test_an_administrator_without_any_settings_permission_gets_no_settings_link(): void
    {
        $this->actingAsPlatformOwner($this->ownerPermissions);

        $menu = $this->accountMenu($this->get(route('admin.platform-owner.overview'))->assertOk()->getContent());

        $this->assertStringNotContainsString('user-menu-settings', $menu);
        $this->assertSame([route('user.account')], $this->menuHrefs($menu));
        // And the page itself stays refused (the admin portal answers 401).
        $this->assertContains($this->get(url(config('app.admin_path') . '/settings'))->getStatusCode(), [401, 403]);
    }

    public function test_backend_staff_get_only_the_settings_page_their_role_may_open(): void
    {
        $staff = User::create([
            'first_name' => 'Backend', 'last_name' => 'Staff', 'email' => 'staff-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend', 'view customer', 'view languages'])]);
        $this->actingAs($staff);

        $html = $this->get(route('admin.staff-home'))->assertOk()->getContent();
        $menu = $this->accountMenu($html);

        $languages = url(config('app.admin_path') . '/languages');
        $this->assertSame([route('user.account'), $languages], $this->menuHrefs($menu));
        $this->assertStringNotContainsString(url(config('app.admin_path') . '/settings') . '"', $menu, 'Platform Settings is not theirs.');
        $this->assertSame('BS', $this->triggerInitials($html));
        $this->get($languages)->assertOk();
    }

    // =================================================================
    // 1-2. Customers: Business owners, team members, Agency accounts
    // =================================================================

    public function test_core_and_growth_business_owners_reach_their_business_settings_hub(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Harbor Lane ' . $tier->value, 'Jazmin Media ' . $tier->value);
            $customer->user->forceFill(['first_name' => 'Jazmin', 'last_name' => 'Reyes'])->save();
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();
            $hub = route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]);

            $this->assertSame('Jazmin Reyes', $this->accountName($html), "[{$tier->value}]");
            $this->assertSame('JR', $this->triggerInitials($html), "[{$tier->value}]");
            $this->assertSame($hub, $this->settingsHref($html), "[{$tier->value}] Settings is the Business's hub.");
            $this->assertSettingsIsTheSidebarsAndOpens($html);

            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_a_team_member_is_offered_settings_only_as_their_sidebar_offers_it(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberUser = User::create([
            'first_name' => 'Team', 'last_name' => 'Member', 'email' => 'team-member-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $memberUser->parent_id = $owner->user_id;
        $memberUser->save();
        $member = Customer::create(['user_id' => $memberUser->id]);
        $membership = $this->member($workspace, $memberUser, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($member);
        $html = $this->home()->assertOk()->getContent();

        $this->assertSame('Team Member', $this->accountName($html));
        $this->assertSame('TM', $this->triggerInitials($html));
        $this->assertSettingsIsTheSidebarsAndOpens($html);
        $this->assertStringNotContainsString(route('customer.workspaces.team.show', $workspace->uid), $this->accountMenu($html));
    }

    public function test_a_person_without_backend_access_gets_no_settings_link(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);
        $this->withSession(['permissions' => collect([])]);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNull($this->settingsHref($html));
        $this->assertSame([], array_values(array_filter($this->menuLinks($html), fn (string $href) => str_contains($href, '/settings'))));
        $this->assertSame([route('user.account'), route('user.account.announcement')], $this->menuHrefs($this->accountMenu($html)));
    }

    public function test_an_agency_account_reaches_agency_settings_and_a_client_business_its_own(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $accountHome = $this->home()->assertOk()->getContent();
        $this->assertSame(route('customer.workspaces.settings.show', $workspace->uid), $this->settingsHref($accountHome), 'Agency settings in the account frame.');
        $this->assertSettingsIsTheSidebarsAndOpens($accountHome);

        $this->switchTo($workspace, $clientOne)->assertRedirect(route('user.home'));
        $inBusiness = $this->home()->assertOk()->getContent();
        $this->assertSettingsIsTheSidebarsAndOpens($inBusiness);
        $this->assertStringContainsString('Client One', $this->shellText($inBusiness), 'The Business context is unchanged.');
    }

    public function test_an_agency_managed_client_owner_gets_their_own_identity_and_settings(): void
    {
        $managed = $this->createAgencyManagedClient(clientBusinessName: 'Maple Dental', clientWorkspaceName: 'Maple Dental Group');
        $client = $managed['clientOwner'];
        $client->user->forceFill(['first_name' => 'Avery', 'last_name' => 'Maple'])->save();
        $this->authenticateAs($client);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame('Avery Maple', $this->accountName($html));
        $this->assertSame('AM', $this->triggerInitials($html));
        $this->assertSettingsIsTheSidebarsAndOpens($html);
        $this->assertStringNotContainsString(route('customer.workspaces.settings.show', $managed['agencyWorkspace']->uid), $this->accountMenu($html), "Never the Agency's own settings.");
    }

    // =================================================================
    // 3. Sign out
    // =================================================================

    public function test_sign_out_posts_with_the_csrf_token_and_signs_the_person_out(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $menu = $this->accountMenu($this->home()->assertOk()->getContent());
        $this->assertSignOutIsAPost($menu);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    // =================================================================
    // 4. Notifications
    // =================================================================

    public function test_the_unread_dot_follows_the_real_unread_state(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $quiet = $this->notificationsRegion($this->home()->assertOk()->getContent());
        $this->assertStringContainsString('data-bs-toggle="dropdown"', $quiet, 'The bell still opens the notification list.');
        $this->assertStringNotContainsString('notification-unread-dot', $quiet);
        $this->assertStringNotContainsString('notification-unread-count', $quiet);

        foreach (['First alert', 'Second alert'] as $message) {
            Notifications::create(['user_id' => $customer->user_id, 'notification_for' => 'customer', 'notification_type' => 'chatbox', 'message' => $message]);
        }
        Notifications::create(['user_id' => $customer->user_id, 'notification_for' => 'customer', 'notification_type' => 'chatbox', 'message' => 'Already read', 'mark_read' => 1]);

        $bell = $this->notificationsRegion($this->home()->assertOk()->getContent());
        $this->assertStringContainsString('data-role="notification-unread-dot"', $bell);
        $this->assertStringContainsString('data-role="notification-unread-count" data-count="2"', $bell);
        $this->assertStringContainsString('First alert', $bell);
        $this->assertStringNotContainsString('Already read', $bell);
        $this->assertStringContainsString('href="' . route('user.account', ['tab' => 'notification']) . '"', $bell);
    }

    // =================================================================
    // 5. Layout safety
    // =================================================================

    public function test_a_long_name_is_one_truncated_label_with_the_full_name_on_hover(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $customer->user->forceFill(['first_name' => 'Maximiliana-Alexandrina', 'last_name' => 'Featherstonehaugh-Montgomery'])->save();
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame('Maximiliana-Alexandrina Featherstonehaugh-Montgomery', $this->accountName($html));
        $this->assertStringContainsString('title="Maximiliana-Alexandrina Featherstonehaugh-Montgomery"', $this->accountRegion($html));
        $this->assertSame('MF', $this->triggerInitials($html));
    }

    public function test_the_top_bar_is_one_shared_control_beside_the_preserved_shell(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<ul [^>]*data-role="topbar-actions"/', $html));
        $this->assertSame(1, preg_match_all('/<li [^>]*data-role="topbar-account"/', $html));
        $this->assertSame(1, preg_match_all('/<li [^>]*data-role="topbar-notifications"/', $html));
        $this->assertSame(1, preg_match_all('/<form id="logout-form"/', $html));
        $this->assertSame(1, preg_match_all('/id="dropdown-user"/', $html), 'One account menu, never a second.');
        // The trigger is a button driving the one Bootstrap dropdown.
        $this->assertMatchesRegularExpression('/<button type="button" class="mg-topbar-btn mg-account-trigger[^"]*" id="dropdown-user"\s+data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"/', $html);
        // The coloured or white bar is the theme's; the component never names a colour.
        $this->assertMatchesRegularExpression('/<nav class="header-navbar[^"]*' . preg_quote(config('custom.vertical.navbarColor'), '/') . '/', $html);
        // The Business identity and switcher in the sidebar are untouched.
        $this->assertStringContainsString('Harbor Lane Studios', $this->shellText($html));
        $this->assertStringContainsString('Business navigation', $html);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertSettingsIsTheSidebarsAndOpens(string $html): void
    {
        $settings = $this->settingsHref($html);
        $sidebarSettings = array_values(array_filter($this->menuLinks($html), fn (string $href) => str_contains($href, '/settings')));

        if ($settings === null) {
            $this->assertSame([], $sidebarSettings, 'The sidebar offers Settings, so the account menu must too.');

            return;
        }

        $this->assertContains($settings, $this->menuLinks($html), 'The account menu never offers a Settings page the sidebar does not.');
        $this->get($settings)->assertOk();
    }

    private function assertSignOutIsAPost(string $menu): void
    {
        $this->assertMatchesRegularExpression(
            '/<form id="logout-form"[^>]*action="' . preg_quote(route('logout'), '/') . '"[^>]*method="POST">\s*<input type="hidden" name="_token" value="[^"]+"[^>]*>\s*<button type="submit" class="dropdown-item mg-account-signout"[^>]*>/',
            $menu,
        );
        $this->assertStringContainsString('Sign out', html_entity_decode(strip_tags($menu)));
        $this->assertStringNotContainsString('href="' . route('logout') . '"', $menu, 'Never a GET link.');
    }

    private function settingsHref(string $html): ?string
    {
        preg_match('/<a class="dropdown-item" href="([^"]+)" data-role="user-menu-settings"/', $this->accountMenu($html), $match);

        return $match[1] ?? null;
    }

    /** @return array<int, string> */
    private function menuHrefs(string $menu): array
    {
        preg_match_all('/<a class="dropdown-item" href="([^"]+)"/', $menu, $links);

        return $links[1];
    }

    private function accountName(string $html): string
    {
        $this->assertMatchesRegularExpression('/data-role="topbar-account-name">([^<]*)</', $html);
        preg_match('/data-role="topbar-account-name">([^<]*)</', $html, $match);

        return html_entity_decode(trim($match[1]));
    }

    private function triggerInitials(string $html): string
    {
        $trigger = $this->between($html, 'id="dropdown-user"', '</button>');
        $this->assertMatchesRegularExpression('/data-role="user-avatar-initials"[^>]*>\s*([^<\s]+)\s*</', $trigger);
        preg_match('/data-role="user-avatar-initials"[^>]*>\s*([^<\s]+)\s*</', $trigger, $match);

        return $match[1];
    }

    private function accountRegion(string $html): string
    {
        return $this->between($html, 'data-role="topbar-account"', '</li>');
    }

    private function accountMenu(string $html): string
    {
        return $this->between($html, 'aria-labelledby="dropdown-user"', '</li>');
    }

    private function notificationsRegion(string $html): string
    {
        return $this->between($html, 'data-role="topbar-notifications"', 'dropdown-menu-footer');
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Missing [{$start}].");
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }
}
