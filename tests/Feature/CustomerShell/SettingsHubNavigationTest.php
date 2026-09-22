<?php

namespace Tests\Feature\CustomerShell;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Walkthrough settings and navigation cleanup (owner decisions).
 *
 *  - The Business sidebar is flat: Conversations is one destination, and
 *    Settings is one destination that opens a Settings hub — no expandable
 *    children anywhere.
 *  - A Core or Growth Business's hub: Business setup, Communication, Account
 *    & billing. The account itself is not a customer-managed object: no
 *    Account module, no account overview, no Business creation.
 *  - Settings → Team is the one team destination.
 *  - An Agency account keeps its own settings at the Agency account level;
 *    a client Business's hub carries only that Business's settings.
 *  - Blocked numbers is gone for Core and Growth; Profile has no Webhook URL.
 */
class SettingsHubNavigationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // =================================================================
    // The sidebar
    // =================================================================

    public function test_automations_sidebar_lands_on_the_v2_workflow_list(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $automationsUrl = route('customer.workspaces.businesses.automations.workflows.index', [$workspace->uid, $business->uid]);

        $this->assertTrue(
            collect($this->menuLinks($html))->contains(fn (string $link): bool => str_contains($link, '/automations/workflows')),
            'Automations sidebar link must target the V2 workflow path.'
        );
        $this->get($automationsUrl)->assertOk()->assertSee('data-role="wf-list-header"', false);
    }

    public function test_core_and_growth_get_a_flat_sidebar_with_one_direct_settings_destination(): void
    {
        // The Opportunity engine is on, so an Advisor entry would render if
        // there still were one.
        config(['opportunity.enabled' => true]);

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Harbor Lane ' . $tier->value, 'Jazmin Media ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();
            $sidebar = $this->sidebarHtml($html);
            $keys = $this->menuKeys($html);

            // Opportunities (the CRM sales board) sits directly after Contacts.
            $expected = ['home', 'conversations', 'contacts', 'opportunities', 'automations', 'website', 'packages_products', 'analytics', 'settings'];
            if ($tier === WorkspacePlanTier::Growth) {
                array_splice($expected, 6, 0, ['gbp']);
            }
            $this->assertSame($expected, $keys, "[{$tier->value}] the contracted flat sidebar, in order — no Advisor.");
            $this->assertStringNotContainsString('Advisor', $this->shellText($html), 'Business Home carries the next best move; there is no separate Advisor module.');

            $this->assertStringNotContainsString('has-sub', $sidebar, 'Nothing expands in the sidebar.');
            $this->assertStringNotContainsString('menu-content', $sidebar);
            $this->assertStringNotContainsString('Messages', $this->shellText($html));

            $links = $this->menuLinks($html);
            $this->assertContains(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]), $links, 'Conversations goes straight to the Business conversations.');
            $automationsUrl = route('customer.workspaces.businesses.automations.workflows.index', [$workspace->uid, $business->uid]);
            $this->assertContains($automationsUrl, $links, 'Automations goes straight to the V2 workflow list.');
            $this->get($automationsUrl)->assertOk()->assertSee('data-role="wf-list-header"', false);
            $this->assertContains(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]), $links, 'Settings goes straight to the hub.');

            foreach (['advisor', 'messages', 'inbox', 'business', 'locations', 'text-messaging', 'usage-billing', 'plan', 'team', 'team-members', 'blocked-numbers', 'account-details', 'advanced'] as $gone) {
                $this->assertNotContains($gone, $keys, "[{$tier->value}] [{$gone}] is not a sidebar entry.");
            }

            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_the_settings_entry_stays_active_on_every_settings_screen(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach ([
            route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]),
            route('customer.workspaces.plan.show', $workspace->uid),
            route('customer.workspaces.team.show', $workspace->uid),
        ] as $url) {
            $this->assertContains('settings', $this->activeMenuKeys($this->get($url)->assertOk()->getContent()), "{$url} keeps Settings active.");
        }
    }

    // =================================================================
    // A Core or Growth Business's Settings hub
    // =================================================================

    public function test_the_core_and_growth_hub_renders_exactly_the_allowed_modules(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Harbor Lane ' . $tier->value, 'Jazmin Media ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->hub($workspace, $business)->getContent();

            $this->assertSame([
                'business-setup' => ['business-details', 'locations'],
                'communication' => ['text-messaging'],
                'billing-team' => ['usage-billing', 'plan', 'team'],
                'features' => [],
            ], $this->settingsHubModules($html), "[{$tier->value}] Business setup, Communication, Billing & team.");

            foreach ([
                'business-details' => route('customer.business.edit'),
                'locations' => route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]),
                'text-messaging' => route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]),
                'usage-billing' => route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]),
                'plan' => route('customer.workspaces.plan.show', $workspace->uid),
                'team' => route('customer.workspaces.team.show', $workspace->uid),
            ] as $module => $url) {
                $this->assertMatchesRegularExpression('/data-module="' . $module . '">\s*<a href="' . preg_quote($url, '/') . '"/', $html, "[{$module}] links to its own screen.");
                $this->get($url)->assertOk();
            }

            // Plain words: the section is "Billing & team" — the technical account
            // is not a thing a Core or Growth customer manages.
            $this->assertMatchesRegularExpression('/data-section="billing-team">.*?Billing &amp; team/s', $html);
            $this->assertStringNotContainsString('Account &amp; billing', $html);

            // The feature switches this Business had on its account page are here.
            $this->assertStringContainsString('data-business-feature-switch', $html);

            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_core_and_growth_have_no_account_destination(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $hub = route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]);

        // The account overview and account settings both send the customer to their Business's Settings.
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertRedirect($hub);
        $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertRedirect($hub);
        $this->get(route('customer.workspaces.index'))->assertRedirect(route('customer.workspaces.show', $workspace->uid));

        $home = $this->home()->assertOk()->getContent();
        $page = $this->hub($workspace, $business)->getContent();

        foreach ([$this->shellHtml($home), $page] as $surface) {
            $this->assertStringNotContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $surface, 'No account page link.');
            $this->assertStringNotContainsString('Account settings', $surface);
        }

        foreach (['account-details', 'Rename', 'Your role', 'Reactivate', 'Transfer'] as $accountControl) {
            $this->assertStringNotContainsString($accountControl, $page);
        }
    }

    public function test_core_and_growth_cannot_create_another_business_from_settings(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $landing = $this->followingRedirects()->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();
        $page = $this->hub($workspace, $business)->getContent();

        foreach ([$landing, $page] as $html) {
            $this->assertStringNotContainsString('data-workspace-action="businesses"', $html);
            $this->assertStringNotContainsString('Create Business', $html);
            $this->assertStringNotContainsString('id="business-name"', $html);
        }
    }

    public function test_a_core_account_with_no_business_yet_still_gets_only_the_first_business_form(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Fresh Account']);
        $this->assignTier($workspace, WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('data-workspace-action="businesses"', $html, 'The first Business can still be made.');
        foreach (['data-workspace-action="rename"', 'Your role', 'Usage &amp; Billing', 'data-workspace-action="members"'] as $accountControl) {
            $this->assertStringNotContainsString($accountControl, $html, "No [{$accountControl}] around it.");
        }
    }

    // =================================================================
    // Team
    // =================================================================

    public function test_there_is_one_canonical_team_destination(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $home = $this->home()->assertOk()->getContent();
        $page = $this->hub($workspace, $business)->getContent();
        $team = route('customer.workspaces.team.show', $workspace->uid);

        $this->assertSame(1, substr_count($page, 'href="' . $team . '"'), 'Settings → Team, once.');
        foreach ([$home, $page] as $html) {
            $this->assertStringNotContainsString(route('customer.sub_accounts.index'), $html, 'No second, delegated-access team entry.');
        }

        $teamPage = $this->get($team)->assertOk()->getContent();
        $this->assertStringContainsString('data-workspace-action="members"', $teamPage, 'Members, roles and access are managed here.');
        $this->assertStringNotContainsString('data-workspace-action="rename"', $teamPage);
        $this->assertStringNotContainsString('data-workspace-action="businesses"', $teamPage);

        // A member action returns to Team.
        $invitee = User::create(['first_name' => 'Mila', 'last_name' => 'Staff', 'email' => 'mila@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
        \App\Models\Customer::create(['user_id' => $invitee->id]);
        $this->post(route('customer.workspaces.members.store', $workspace->uid), ['member_email' => 'mila@example.test', 'role' => 'staff', 'business_access_scope' => 'selected', 'business_uids' => [$business->uid]])
            ->assertRedirect($team);
    }

    public function test_an_agency_assigns_client_access_from_its_team_page_and_its_overview_keeps_client_creation(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        // One Business to grant, so the page grants exactly it — least
        // privilege, never a hidden "all Businesses" scope and never a choice
        // with one row.
        $team = $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="member-single-business"', $team);
        $this->assertStringContainsString('<input type="hidden" name="business_access_scope" value="selected">', $team);
        $this->assertStringContainsString('name="business_uids[]"', $team);
        $this->assertStringNotContainsString('id="member-scope" name="business_access_scope"', $team, 'Nothing to choose between.');

        $overview = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('data-workspace-action="businesses"', $overview, 'Agency client creation remains.');
        $this->assertStringNotContainsString('data-workspace-action="members"', $overview, 'Members live on Team, not twice.');
    }

    // =================================================================
    // Agency
    // =================================================================

    public function test_agency_account_settings_remain_available_at_the_agency_account_level(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $home = $this->home()->assertOk()->getContent();
        $this->assertContains(route('customer.workspaces.settings.show', $workspace->uid), $this->menuLinks($home));
        $this->assertStringNotContainsString('has-sub', $this->sidebarHtml($home));

        $html = $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent();
        $modules = $this->settingsHubModules($html);

        $this->assertSame(['account-details', 'plan', 'team'], $modules['account'] ?? null);
        $this->assertSame(['blocked-numbers'], $modules['outreach'] ?? null, 'The legacy blocked numbers stay with the Agency, unchanged.');
        $this->assertContains('messaging-provider', $modules['advanced'] ?? [], 'Advanced provider settings for the owner.');
        $this->assertStringContainsString('Agency account settings', $html);
    }

    public function test_a_client_business_does_not_inherit_agency_account_settings(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchTo($workspace, $clientOne)->assertRedirect(route('user.home'));

        $this->assertContains(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $clientOne->uid]), $this->menuLinks($this->home()->assertOk()->getContent()));

        $modules = $this->settingsHubModules($this->hub($workspace, $clientOne)->getContent());

        $this->assertSame(['communication', 'billing-team'], array_values(array_diff(array_keys($modules), ['business-setup'])));
        $this->assertSame(['usage-billing'], $modules['billing-team'], "Only the client's own billing.");
        foreach (['plan', 'team', 'account-details', 'blocked-numbers', 'messaging-provider'] as $agencyOnly) {
            $this->assertNotContains($agencyOnly, array_merge(...array_values($modules)), "[{$agencyOnly}] belongs to the Agency account.");
        }
        $this->assertArrayNotHasKey('features', $modules, "A client's switches live on the Agency account page.");
    }

    // =================================================================
    // Permissions
    // =================================================================

    public function test_permissions_still_hide_settings_the_actor_cannot_use(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        // Staff without the numbers permission.
        $this->authenticateAs($staff, array_values(array_diff($this->allCustomerPermissions(), ['view_numbers'])));

        $modules = $this->settingsHubModules($this->hub($workspace, $business)->getContent());

        $this->assertSame(['business-setup' => ['locations']], $modules, 'No details (not theirs), no texting (no permission), no billing, plan or team, no switches.');
        $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertNotFound();
        $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertNotFound();
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertNotFound();
    }

    public function test_viewing_as_a_client_keeps_only_business_scoped_settings(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client, 'Settings hub check.')->assertRedirect();

        $modules = array_merge(...array_values($this->settingsHubModules($this->hub($workspace, $client)->getContent())));

        $this->assertContains('locations', $modules);
        foreach (['business-details', 'plan', 'team', 'account-details'] as $accountLevel) {
            $this->assertNotContains($accountLevel, $modules);
        }
    }

    // =================================================================
    // Blocked numbers and Profile
    // =================================================================

    public function test_blocked_numbers_is_absent_for_core_and_growth(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Harbor Lane ' . $tier->value, 'Jazmin Media ' . $tier->value);
            $this->authenticateAs($customer);

            foreach ([$this->home()->assertOk()->getContent(), $this->hub($workspace, $business)->getContent()] as $html) {
                $this->assertStringNotContainsString(route('customer.blacklists.index'), $html, "[{$tier->value}] no legacy blacklist entry.");
                $this->assertStringNotContainsString('Blocked numbers', $html);
            }

            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_profile_has_no_webhook_url(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->get(route('user.account'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="webhook-tab"', $html);
        $this->assertStringNotContainsString('name="webhook_url"', $html);
        $this->assertStringNotContainsString(route('customer.developer.webhook'), $html);
        $this->assertStringNotContainsString(__('locale.developers.webhook_url'), $html);
    }

    // -----------------------------------------------------------------

    private function hub(Workspace $workspace, Business $business): TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk();
    }
}
