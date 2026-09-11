<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Helpers\Helper;
use App\Library\Navigation\CustomerContextPreference;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — canonical context resolution, the six
 * §9.3 experiences (T-CTX-1), Workspace invisibility for Core/Growth
 * (T-CTX-2), frame separation (T-CTX-5), the Business switcher, multiple
 * Workspaces, remembered-preference re-authorization, and the E-11
 * campaign-link correction.
 */
class CustomerContextResolutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const BUSINESS_FRAME_KEYS = ['home', 'messages', 'inbox', 'contacts', 'automations', 'website', 'gbp', 'analytics', 'settings'];

    private const ACCOUNT_FRAME_ONLY_KEYS = ['accounts', 'prospecting'];

    private const ADVANCED_KEYS = ['advanced', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'];

    // -----------------------------------------------------------------
    // T-CTX-1 / T-CTX-2 — Core and Growth
    // -----------------------------------------------------------------

    public function test_single_business_growth_owner_lands_directly_in_the_business_frame(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->home()->assertOk();
        $html = $response->getContent();
        $keys = $this->menuKeys($html);

        foreach (array_merge(self::BUSINESS_FRAME_KEYS, ['business-details', 'blocked-numbers', 'usage-billing', 'team', 'plan']) as $expected) {
            $this->assertContains($expected, $keys, "Business frame must offer {$expected}.");
        }

        foreach (array_merge(self::ACCOUNT_FRAME_ONLY_KEYS, self::ADVANCED_KEYS) as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Core/Growth must never see {$forbidden} (§8.2, §8.6).");
        }

        // Compact identity, no pointless switcher (§9.2).
        $response->assertSee('data-role="context-identity"', false);
        $response->assertDontSee('customer-context-switcher-toggle', false);
        $this->assertStringContainsString($business->name, $this->shellText($html));

        // E-11 is moot now that Messages is Inbox only: neither the canonical
        // campaign list nor the bare legacy entry is a menu destination.
        $this->assertNotContains(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $business->uid]), $this->menuLinks($html));
        $this->assertNotContains(url('outreach/campaigns'), $this->menuLinks($html));
    }

    public function test_core_and_growth_shells_never_say_workspace(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Tier ' . $tier->value);
            $this->authenticateAs($customer);

            $pages = [
                $this->home(),
                $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid])),
                $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $business->uid])),
            ];

            foreach ($pages as $page) {
                $page->assertOk();
                $this->assertStringNotContainsStringIgnoringCase('workspace', $this->shellText($page->getContent()), 'T-CTX-2: the ' . $tier->value . ' shell must not say Workspace.');
            }
        }
    }

    public function test_a_single_business_user_is_never_forced_through_a_selector(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $this->home()->assertOk();
        $this->assertNotContains('accounts', $this->menuKeys($this->home()->getContent()));

        // Bare entries resolve straight through, deterministic and loop-free.
        $this->get(route('customer.analytics.entry'))
            ->assertRedirect(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]));
        $this->get(route('customer.outreach.campaigns.entry'))
            ->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $business->uid]));
        $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $business->uid]))->assertOk();
    }

    // -----------------------------------------------------------------
    // T-CTX-1 — Agency owner, staff, client owner, restricted staff
    // -----------------------------------------------------------------

    public function test_agency_owner_gets_the_account_frame_and_a_client_switcher(): void
    {
        [$customer, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $clientTwo = $this->addBusiness($customer, $workspace, 'Client Two');
        $this->authenticateAs($customer);

        $response = $this->home()->assertOk();
        $html = $response->getContent();
        $keys = $this->menuKeys($html);

        foreach (['home', 'accounts', 'prospecting', 'settings', 'plan', 'advanced', 'sender-ids', 'numbers', 'keywords', 'messaging-provider'] as $expected) {
            $this->assertContains($expected, $keys, "Agency account frame must offer {$expected} (§8.3, §8.6).");
        }

        foreach (['messages', 'inbox', 'send', 'contacts', 'campaigns', 'website', 'gbp', 'analytics', 'automations'] as $businessOnly) {
            $this->assertNotContains($businessOnly, $keys, 'No Business entry before a client account is selected (§8.1).');
        }

        $shell = $this->shellText($html);
        $this->assertStringContainsString('Client accounts', $shell);
        $this->assertStringContainsString('Northwind Agency', $shell);
        $response->assertSee('id="customer-context-switcher-toggle"', false);
        $response->assertSee('Client One', false);
        $response->assertSee('Client Two', false);
        $response->assertSee('View Client One as a client', false);

        // Explicit selection enters the Business frame of that client.
        $this->switchTo($workspace, $clientTwo)->assertRedirect(route('user.home'));

        $after = $this->home()->assertOk();
        $afterKeys = $this->menuKeys($after->getContent());

        foreach (self::BUSINESS_FRAME_KEYS as $expected) {
            $this->assertContains($expected, $afterKeys);
        }

        $this->assertNotContains('accounts', $afterKeys);
        $this->assertStringContainsString('Client Two', $this->shellText($after->getContent()));
        $after->assertSee('aria-current="true"', false);
        $after->assertSee('All client accounts', false);
        $this->assertContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientTwo->uid]), $this->menuLinks($after->getContent()));
        $this->assertNotContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]), $this->menuLinks($after->getContent()));
    }

    public function test_selected_scope_staff_land_in_their_sole_assigned_business_without_a_switcher(): void
    {
        [$owner, $assigned, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Assigned Client', 'Northwind Agency');
        $unassigned = $this->addBusiness($owner, $workspace, 'Unassigned Client');
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $assigned);
        $this->authenticateAs($staff, ['view_contact', 'view_reports', 'sms_campaign_builder', 'automations', 'website', 'view_google_business_profile', 'chat_box']);

        $response = $this->home()->assertOk();
        $html = $response->getContent();
        $keys = $this->menuKeys($html);

        $this->assertContains('analytics', $keys);
        $this->assertContains('inbox', $keys);
        $response->assertSee('data-role="context-identity"', false);
        $response->assertDontSee('customer-context-switcher-toggle', false);
        $this->assertStringContainsString('Assigned Client', $this->shellText($html));
        $this->assertStringNotContainsString('Unassigned Client', $html);

        foreach (['accounts', 'prospecting', 'team', 'plan', 'usage-billing', 'advanced'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Staff never see {$forbidden} (§6, §9.3).");
        }
    }

    public function test_client_business_owner_inside_an_agency_sees_only_their_business_frame(): void
    {
        [$agencyOwner, $agencyOwnBusiness, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency House Business', 'Northwind Agency');
        $client = $this->createCustomer();
        $clientBusiness = $this->addBusiness($client, $workspace, 'Client Bakery');
        $membership = $this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $clientBusiness);
        $this->authenticateAs($client);

        $response = $this->home()->assertOk();
        $html = $response->getContent();
        $keys = $this->menuKeys($html);

        foreach (self::BUSINESS_FRAME_KEYS as $expected) {
            $this->assertContains($expected, $keys);
        }

        foreach (array_merge(self::ACCOUNT_FRAME_ONLY_KEYS, self::ADVANCED_KEYS, ['team', 'plan']) as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "A client owner must not see {$forbidden} (§5.4).");
        }

        $shell = $this->shellText($html);
        $this->assertStringContainsString('Client Bakery', $shell);
        $this->assertStringNotContainsString('Northwind Agency', $html, 'S-6: the Agency Workspace name is never disclosed to a client.');
        $this->assertStringNotContainsString('Agency House Business', $html);
        $this->assertStringNotContainsStringIgnoringCase('workspace', $shell);
        $response->assertDontSee('as a client', false);
    }

    public function test_restricted_business_staff_get_a_reduced_menu_from_their_permissions(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff, ['view_contact']);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->assertSame(['home', 'contacts'], $keys, 'Only Home and the permitted Contacts entry remain (§9.3 #6).');
    }

    public function test_the_platform_owner_shell_carries_no_customer_navigation(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $adminId = $this->platformAdminId();
        $admin = \App\Models\User::findOrFail($adminId);
        $admin->email_verified_at = now();
        $admin->save();

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        $response = $this->get(route('admin.home'))->assertOk();
        $response->assertDontSee('data-nav-key=', false);
        $response->assertDontSee('data-role="context-switcher"', false);
        $response->assertDontSee('data-role="sidebar-context"', false);
    }

    // -----------------------------------------------------------------
    // Multiple Workspaces, preferences, inactive states
    // -----------------------------------------------------------------

    public function test_multiple_workspaces_are_never_resolved_by_database_order(): void
    {
        [$customer, $firstBusiness, $firstWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'First Business', 'First Account');
        $secondWorkspace = $this->createWorkspace($customer->user, ['name' => 'Second Account']);
        $secondBusiness = $this->addBusiness($customer, $secondWorkspace, 'Second Business');
        $this->assignTier($secondWorkspace, WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->home()->assertOk();
        $html = $response->getContent();

        $this->assertContains('accounts', $this->menuKeys($html), 'Ambiguous accounts require an explicit choice.');
        $this->assertStringContainsString('Choose an account', $this->shellText($html));
        $response->assertDontSee('data-role="context-identity"', false);
        $this->assertNotContains('contacts', $this->menuKeys($html), 'No Business frame is guessed from database order.');
        $response->assertSee('id="customer-context-switcher-toggle"', false);
        $response->assertSee('First Account', false);
        $response->assertSee('Second Account', false);

        // Choosing the second account (its own page) makes its sole Business unambiguous.
        $this->get(route('customer.workspaces.show', $secondWorkspace->uid))->assertOk();
        $afterChoice = $this->home()->assertOk();
        $this->assertStringContainsString('Second Business', $this->shellText($afterChoice->getContent()));
        $this->assertContains('contacts', $this->menuKeys($afterChoice->getContent()));

        // And an explicit switch to the first Business re-authorizes and re-frames.
        $this->switchTo($firstWorkspace, $firstBusiness)->assertRedirect(route('user.home'));
        $this->assertStringContainsString('First Business', $this->shellText($this->home()->getContent()));
    }

    public function test_inactive_workspace_membership_and_business_states_fail_safely(): void
    {
        // Inactive Workspace: nothing is selectable, nothing is guessed.
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Dormant Business', 'Dormant Account');
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->authenticateAs($owner);
        $home = $this->home()->assertOk();
        $this->assertNotContains('contacts', $this->menuKeys($home->getContent()));
        $this->assertStringContainsString('No business yet', $this->shellText($home->getContent()));
        $this->switchTo($workspace, $business)->assertNotFound();

        // Inactive membership grants no Workspace-derived access.
        [$agencyOwner, $agencyBusiness, $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Live Client', 'Live Agency');
        $former = $this->createCustomer();
        $this->member($agencyWorkspace, $former->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);
        $this->authenticateAs($former);
        $formerHome = $this->home()->assertOk();
        $this->assertStringNotContainsString('Live Client', $formerHome->getContent());
        $this->switchTo($agencyWorkspace, $agencyBusiness)->assertNotFound();

        // A draft Business is visible as not active, never selected.
        [$draftOwner, $draftBusiness, $draftWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Draft Business', 'Draft Account');
        DB::table('businesses')->where('id', $draftBusiness->id)->update(['status' => BusinessStatus::Draft->value]);
        $this->authenticateAs($draftOwner);
        $draftHome = $this->home()->assertOk();
        $this->assertNotContains('analytics', $this->menuKeys($draftHome->getContent()));
        $this->switchTo($draftWorkspace, $draftBusiness)->assertNotFound();
    }

    public function test_a_remembered_preference_is_reauthorized_on_every_use_and_cleared_when_access_is_revoked(): void
    {
        [$owner, $assigned, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Assigned Client', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Other Client');
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $assigned);
        $this->authenticateAs($staff);

        $this->switchTo($workspace, $assigned)->assertRedirect(route('user.home'));
        $this->assertSame($assigned->uid, session(CustomerContextPreference::SESSION_KEY)['business'] ?? null);

        WorkspaceMembershipBusiness::query()->where('workspace_membership_id', $membership->id)->delete();

        $home = $this->home()->assertOk();
        $this->assertStringNotContainsString('Assigned Client', $home->getContent());
        $this->assertNull(session(CustomerContextPreference::SESSION_KEY)['business'] ?? null, 'A revoked preference is cleared, never honoured.');
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $assigned->uid]))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Locations, frame separation, redirects, legacy targets
    // -----------------------------------------------------------------

    public function test_the_business_switcher_never_lists_physical_locations(): void
    {
        [$customer, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($customer, $workspace, 'Client Two');

        foreach (['Downtown Storefront Location', 'Riverside Branch Location', 'Mobile Service Area Location'] as $index => $name) {
            BusinessLocation::create([
                'business_id' => $clientOne->id,
                'name' => $name,
                'service_mode' => BusinessServiceMode::Storefront,
                'address_line_1' => ($index + 1) . ' Harbor Lane',
                'city' => 'Brooklyn',
                'region' => 'NY',
                'postal_code' => '11201',
                'country_code' => 'US',
                'public_address' => true,
                'is_primary' => $index === 0,
            ]);
        }

        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('Client One', $this->shellHtml($html));
        $this->assertStringContainsString('Client Two', $this->shellHtml($html));

        foreach (['Downtown Storefront Location', 'Riverside Branch Location', 'Mobile Service Area Location'] as $name) {
            $this->assertStringNotContainsString($name, $html, 'S-4: a BusinessLocation is never a switcher entry.');
        }
    }

    public function test_business_routes_never_mark_an_account_frame_item_active(): void
    {
        [$customer, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $clientTwo = $this->addBusiness($customer, $workspace, 'Client Two');
        $this->authenticateAs($customer);
        $this->switchTo($workspace, $clientTwo);

        $analytics = $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientTwo->uid]))->assertOk();
        $this->assertSame(['analytics'], $this->activeMenuKeys($analytics->getContent()));
        $this->assertNotContains('accounts', $this->menuKeys($analytics->getContent()));
        $this->assertSame(1, substr_count($this->sidebarHtml($analytics->getContent()), 'aria-current="page"'));

        // The legacy campaigns page is no longer a menu destination, so it
        // marks nothing active — least of all an Account-frame item.
        $campaigns = $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $clientTwo->uid]))->assertOk();
        $this->assertSame([], $this->activeMenuKeys($campaigns->getContent()));
        $this->assertNotContains('accounts', $this->menuKeys($campaigns->getContent()));

        $team = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $this->assertSame(['team'], $this->activeMenuKeys($team->getContent()), 'The Workspace page is reached as Settings → Team inside the Business frame, never as an Account-frame item.');
    }

    public function test_automatic_redirects_are_deterministic_and_never_loop(): void
    {
        [$customer, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($customer, $workspace, 'Client Two');
        $this->authenticateAs($customer);

        // Ambiguous: the bare campaigns entry hands over to the explicit chooser, which renders.
        $this->get(route('customer.outreach.campaigns.entry'))->assertRedirect(route('customer.outreach.index'));
        $this->get(route('customer.outreach.index'))->assertOk();

        // Selected: the same entry goes straight to the canonical list, which renders.
        $this->switchTo($workspace, $clientOne);
        $this->get(route('customer.outreach.campaigns.entry'))
            ->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $clientOne->uid]));
        $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $clientOne->uid]))->assertOk();
    }

    public function test_existing_canonical_business_urls_remain_valid(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach ([
            route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.outreach.campaigns', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]),
            route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_target_of_the_retained_legacy_menu_array_resolves_to_a_route(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $urls = [];
        $walk = function (array $entries) use (&$walk, &$urls): void {
            foreach ($entries as $entry) {
                if (! empty($entry['url'])) {
                    $urls[] = $entry['url'];
                }

                if (! empty($entry['submenu'])) {
                    $walk($entry['submenu']);
                }
            }
        };
        $walk(Helper::menuData()['customer']);

        $this->assertContains(url('outreach/campaigns'), $urls, 'The E-11 target is still declared by the legacy array…');

        foreach ($urls as $url) {
            $this->assertUrlMatchesARegisteredRoute($url);
        }
    }

    // -----------------------------------------------------------------
    // Customer Experience Slice 1A (Correction 3) — T-TERM-1: the raw
    // word "Workspace" is replaced by CustomerContext::accountNoun()/
    // accountsNoun() everywhere it used to leak, across every reachable
    // role/context including the unselected-multi-workspace chooser and
    // Agency Prospecting.
    // -----------------------------------------------------------------

    public function test_the_entry_chooser_pages_use_account_vocabulary_not_workspace(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        foreach ([
            route('customer.automations.index'),
            route('customer.analytics.entry'),
            route('customer.website.index'),
            route('customer.outreach.index'),
            route('customer.gbp.index'),
        ] as $url) {
            $page = $this->get($url)->assertOk();

            // /workspaces/{workspaceUid} is a permitted technical URL path
            // (contract §2) — only visible text, attributes stripped, is
            // checked for the forbidden customer-copy noun.
            $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($page->getContent()), "T-TERM-1: {$url} must never render Workspace.");
            $page->assertSee('ask an account owner', false);
        }
    }

    public function test_back_links_and_slot_pages_use_account_vocabulary_not_workspace(): void
    {
        [$growth, $growthBusiness, $growthWorkspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($growth);

        $overview = $this->get(route('customer.workspaces.businesses.analytics.overview', [$growthWorkspace->uid, $growthBusiness->uid]))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($overview->getContent()));
        $overview->assertSee('Back to Account', false);

        [$agency, , $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $slots = $this->get(route('customer.workspaces.additional-business-slots.show', $agencyWorkspace->uid))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($slots->getContent()));
        $slots->assertSee('Back to Agency account', false);
    }

    public function test_multi_workspace_chooser_body_uses_neutral_account_vocabulary(): void
    {
        [$customer, $firstBusiness, $firstWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'First Business', 'First Account');
        $secondWorkspace = $this->createWorkspace($customer->user, ['name' => 'Second Account']);
        $this->addBusiness($customer, $secondWorkspace, 'Second Business');
        $this->assignTier($secondWorkspace, WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        // Ambiguous — frameWorkspace() is null (Correction 3 §5's resolved
        // "no third noun" rule: this state reads as neutral "account", never
        // "Agency account" and never the raw word "Workspace").
        $chooser = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($chooser->getContent()));
        // A chooser, never a "create another account" screen.
        $chooser->assertDontSee('New account name', false);
        $chooser->assertDontSee('Create account', false);
        $chooser->assertSee('Choose an account', false);
        $chooser->assertSee('First Account', false);
        $chooser->assertSee('Second Account', false);

        // Once a frame is selected, the per-account page also stays clean.
        $show = $this->get(route('customer.workspaces.show', $firstWorkspace->uid))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($show->getContent()));
        $show->assertSee('Account overview', false);
    }

    public function test_agency_prospecting_pages_use_account_vocabulary_not_workspace(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        // Ambiguous multi-workspace prospecting chooser (Correction 3 §5:
        // no third noun invented — "account", not "Agency account", for
        // this state).
        $second = $this->createWorkspace($agency->user, ['name' => 'Second Agency']);
        $this->assignTier($second, WorkspacePlanTier::Agency);

        $entry = $this->get(route('customer.prospecting.index'))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($entry->getContent()));

        // A specific, proven Agency frame reads "this Agency account".
        $channels = $this->get(route('customer.workspaces.prospecting.channels.index', $workspace->uid))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('workspace', $this->visibleBodyText($channels->getContent()));
        $channels->assertSee('this Agency account', false);
    }

    /**
     * Visible page text with every HTML attribute and the contents of
     * <script>/<style> blocks stripped — the technical URL path
     * /workspaces/{workspaceUid} is a permitted route segment (contract
     * §2) and appears both in href/action attributes and inline JS
     * variables (e.g. `var seriesUrl = ".../workspaces/..."`), never as
     * customer copy, so it must not fail a T-TERM-1 assertion the way a
     * raw substring check on the full response would.
     */
    private function visibleBodyText(string $html): string
    {
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html) ?? '';
        $withoutStyles = preg_replace('#<style\b[^>]*>.*?</style>#si', '', $withoutScripts) ?? '';
        $stripped = preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $withoutStyles) ?? '';

        return html_entity_decode(strip_tags($stripped));
    }
}
