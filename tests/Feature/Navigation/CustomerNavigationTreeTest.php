<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Redesign — Slice 2A, the §13 test matrix.
 *
 * The slice has a single acceptance question: does a menu entry appear
 * exactly when route, permission, access AND entitlement all allow it,
 * within six queries? Everything below answers one half of that.
 *
 * ON ENTITLEMENT FIXTURES. The seeded catalog is the real one, not a stub:
 * migration 2026_08_13_120007 gives Core `automations` and
 * `website_generation` but NOT `google_business_profile_module`, and
 * 2026_09_09_120004 adds that module to Growth and Agency only. That is why
 * Core legitimately sees Automations and Website but not Get found — the
 * D-20 exemplar the contract names, reproduced from shipped data rather than
 * arranged for the test.
 *
 * ON THE ADVANCED GROUP. Slice 3's final merged rule is authoritative
 * Workspace OWNERSHIP plus `manage_advanced_provider` — not canManage(), not
 * admin membership. An agency-wide active Admin therefore sees no Advanced
 * group, and that is asserted directly rather than assumed.
 */
class CustomerNavigationTreeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /** Entries that may only ever exist while a Business is selected. */
    private const BUSINESS_ONLY_KEYS = [
        'conversations', 'messages', 'inbox', 'send', 'campaigns', 'contacts',
        'automations', 'website', 'gbp', 'analytics',
    ];

    // =================================================================
    // §13 #1, #2 — the exact Business trees
    // =================================================================

    public function test_a_core_business_gets_the_contracted_tree_without_get_found(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['home', 'conversations', 'contacts', 'automations', 'website', 'analytics', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "A Core Business must offer [{$expected}].");
        }

        foreach (['send', 'campaigns', 'messages', 'inbox'] as $gone) {
            $this->assertNotContains($gone, $keys, "Conversations is the one messaging entry: no [{$gone}].");
        }

        // The D-20 exemplar: Core's catalog excludes the GBP module.
        $this->assertNotContains('gbp', $keys, 'Core has no Get found.');
    }

    /**
     * Customer Experience Slice 1A, re-homed by the Settings hub — Locations is
     * a Business setup module of the selected Business's Settings, pointing at
     * that Business's own scoped route. No entitlement key and no entitlement
     * query are involved.
     */
    public function test_locations_sits_under_settings_business_setup_for_the_selected_business(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertNotContains('locations', $this->menuKeys($this->home()->assertOk()->getContent()), 'Not a sidebar entry any more.');

        $hub = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertContains('locations', $this->settingsHubModules($hub)['business-setup'] ?? [], 'Settings → Business setup → Locations.');
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]) . '"', $hub);
    }

    public function test_a_growth_business_gets_get_found_because_its_plan_includes_it(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        foreach (['home', 'conversations', 'contacts', 'automations', 'website', 'gbp', 'analytics', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "A Growth Business must offer [{$expected}].");
        }

        foreach (['send', 'campaigns', 'messages', 'inbox'] as $gone) {
            $this->assertNotContains($gone, $keys, "Conversations is the one messaging entry: no [{$gone}].");
        }

        $this->assertStringContainsString('Get found', $this->shellText($html));
    }

    /**
     * Owner product decision — "remove Messaging channel from normal UX".
     * Core and Growth both offer the plain-language Text messaging status
     * item, and neither offers the Agency-only Advanced (BYO Messaging
     * provider) surface.
     */
    public function test_core_and_growth_offer_text_messaging_but_never_the_advanced_byo_surface(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $keys = array_merge(
                $this->menuKeys($this->home()->assertOk()->getContent()),
                $this->settingsHubModuleKeys($this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent()),
            );

            $this->assertContains('text-messaging', $keys, "[{$tier->value}] must offer Text messaging.");
            $this->assertNotContains('advanced', $keys, "[{$tier->value}] must never offer the Agency-only Advanced surface.");
            $this->assertNotContains('messaging-provider', $keys, "[{$tier->value}] must never offer Messaging provider.");

            auth()->logout();
            $this->flushSession();
        }
    }

    /**
     * Owner decision — Conversations is one direct destination, not a
     * "Messages → Inbox" group; the legacy outbound Send and Campaigns pages
     * are not customer destinations (their routes stay registered).
     */
    public function test_conversations_is_one_direct_entry_with_nothing_under_it(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        $conversations = array_search('conversations', $keys, true);

        $this->assertNotFalse($conversations);
        $this->assertSame('contacts', $keys[$conversations + 1] ?? null, 'Nothing nested under Conversations.');
        $this->assertDoesNotMatchRegularExpression('/<li class="[^"]*has-sub[^"]*" data-nav-key="conversations"/', $this->sidebarHtml($html));
    }

    // =================================================================
    // §13 #3, #4 — the Account frame and the Advanced authority
    // =================================================================

    public function test_an_agency_owner_gets_the_account_tree_with_advanced(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $keys = array_merge(
            $this->menuKeys($this->home()->assertOk()->getContent()),
            $this->settingsHubModuleKeys($this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent()),
        );

        foreach (['home', 'accounts', 'prospecting', 'settings', 'plan', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $expected) {
            $this->assertContains($expected, $keys, "An Agency owner must offer [{$expected}].");
        }
    }

    /**
     * §13 #4 — the FINAL rule, not the one the contract was drafted against.
     *
     * Slice 3 narrowed Advanced to authoritative Workspace ownership. An
     * agency-wide active Admin passes canManage() and would have seen the
     * group under the old rule; under the merged rule the controller answers
     * 404, so the menu must not offer it.
     */
    public function test_an_agency_wide_admin_who_is_not_the_owner_gets_no_advanced_group(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        $adminCustomer = $this->createCustomer();
        $this->member($workspace, $adminCustomer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $this->authenticateAs($adminCustomer);

        $hub = fn () => $this->settingsHubModules($this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent());
        $modules = $hub();

        $this->assertArrayNotHasKey('advanced', $modules, 'An agency-wide Admin is not the owner and gets no Advanced section.');

        foreach (['messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $forbidden) {
            $this->assertNotContains($forbidden, array_merge(...array_values($modules)), "An agency-wide Admin is not the owner and must not see [{$forbidden}].");
        }

        // The owner of the same Workspace still does — the rule narrows by
        // ownership, it does not switch the surface off.
        $this->authenticateAs($owner);
        $this->assertContains('messaging-provider', $hub()['advanced'] ?? []);
    }

    public function test_the_advanced_group_also_requires_the_manage_advanced_provider_permission(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        // Ownership alone is never sufficient — the permission is stacked.
        $withoutPermission = array_values(array_filter(
            $this->allCustomerPermissions(),
            static fn (string $permission): bool => $permission !== 'manage_advanced_provider',
        ));

        $this->authenticateAs($agency, $withoutPermission);

        $this->assertArrayNotHasKey('advanced', $this->settingsHubModules($this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent()));
    }

    // =================================================================
    // §13 #9 — the frames never leak into each other
    // =================================================================

    public function test_the_account_frame_offers_no_business_only_entry(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (self::BUSINESS_ONLY_KEYS as $businessOnly) {
            $this->assertNotContains($businessOnly, $keys, "[{$businessOnly}] belongs to the Business frame only.");
        }
    }

    public function test_the_business_frame_offers_no_account_only_entry(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['accounts', 'prospecting'] as $accountOnly) {
            $this->assertNotContains($accountOnly, $keys, "[{$accountOnly}] belongs to the Account frame only.");
        }
    }

    // =================================================================
    // §13 #12, #13 — Developers, and the phantom leaves
    // =================================================================

    public function test_developers_is_absent_from_every_frame_tier_and_role(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();

            $this->assertNotContains('developers', $this->menuKeys($html), "[{$tier->value}] must not offer Developers.");
            $this->assertStringNotContainsString('Developers', $this->shellText($html));
        }
    }

    /** Removed from the menu only — the routes stay registered and reachable. */
    public function test_the_developer_routes_are_still_registered(): void
    {
        foreach (['customer.developer.settings', 'customer.developer.generate'] as $name) {
            $this->assertNotNull(
                app('router')->getRoutes()->getByName($name),
                "[{$name}] must remain registered; 2A removes the menu entry, not the feature.",
            );
        }
    }

    public function test_no_invoices_team_or_templates_leaf_is_emitted(): void
    {
        foreach ([WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();
            $keys = $this->menuKeys($html);

            foreach (['invoices', 'templates'] as $phantom) {
                $this->assertNotContains($phantom, $keys, "[{$phantom}] names no destination and must not be emitted.");
            }

            $text = $this->shellText($html);

            $this->assertStringNotContainsString('Invoices', $text);
            $this->assertStringNotContainsString('Templates', $text);
        }
    }

    // =================================================================
    // §13 #14, #15, #16, #17 — entitlement is the fourth gate
    // =================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedFeatureProvider(): array
    {
        return [
            'automations' => ['automations', 'automations'],
            'website' => ['website_generation', 'website'],
            'get found' => ['google_business_profile_module', 'gbp'],
        ];
    }

    /**
     * @dataProvider gatedFeatureProvider
     */
    public function test_a_denied_plan_feature_removes_its_entry(string $featureKey, string $menuKey): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertContains($menuKey, $this->menuKeys($this->home()->assertOk()->getContent()), 'Precondition: entitled.');

        // Deny at the Business layer — step 8 of the same precedence. The
        // actor must be able to manage the Workspace, so the owner performs
        // it, exactly as a real per-Business disable would be performed.
        app(EntitlementManager::class)->disableBusinessFeature(
            $business,
            PlatformFeature::from($featureKey),
            (int) $customer->user_id,
            'Slice 2A navigation test.',
        );

        $this->assertNotContains(
            $menuKey,
            $this->menuKeys($this->home()->assertOk()->getContent()),
            "[{$menuKey}] must disappear when [{$featureKey}] is denied.",
        );
    }

    /**
     * §13 #15 — the entry is gone even though the actor holds every
     * permission that reaches it. Permission is necessary, never sufficient.
     */
    public function test_permission_alone_cannot_expose_a_plan_excluded_feature(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);

        $this->assertContains('view_google_business_profile', $this->allCustomerPermissions());

        $this->authenticateAs($customer);

        $this->assertNotContains('gbp', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    /** §13 #17 — Contacts is deliberately NOT entitlement-gated. */
    public function test_contacts_is_visible_for_every_tier(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, , $workspace] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);

            if ($tier === WorkspacePlanTier::Agency) {
                // An Agency owner starts in the Account frame; Contacts is a
                // Business-frame entry, so select the Business first.
                $this->authenticateAs($customer);
                $business = $workspace->businesses()->first();
                $this->switchTo($workspace, $business);
            } else {
                $this->authenticateAs($customer);
            }

            $this->assertContains(
                'contacts',
                $this->menuKeys($this->home()->assertOk()->getContent()),
                "[{$tier->value}] must keep Contacts.",
            );
        }
    }

    // =================================================================
    // §13 #18, #19, #20 — the query budget
    // =================================================================

    public function test_menu_entitlement_resolution_costs_at_most_six_queries(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $queries = $this->countQueriesResolving($workspace, $business, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES, $customer->user_id);

        $this->assertLessThanOrEqual(6, $queries, "Menu entitlement resolution used {$queries} queries; the budget is 6.");
        $this->assertGreaterThan(0, $queries, 'It must actually resolve something.');
    }

    /**
     * §13 #19 — the decisive property. The budget is not "six for three
     * features", it is six however many are asked, because every per-feature
     * read is bulk.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_features(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $three = CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES;
        $baseline = $this->countQueriesResolving($workspace, $business, $three, $customer->user_id);

        // Shared customer request query-budget optimization (Automations
        // V2 §18) — snapshotBusinessFeatureDecisions()'s own reads are now
        // memoized per REQUEST (RequestScopedCache, keyed off the current
        // Illuminate Request the same way CustomerShellComposer's own menu
        // snapshot already is). Both measurements below ask about the same
        // Workspace/Business, so without a fresh request between them the
        // second would be served entirely from the first's cache — a
        // stronger form of the very flatness this test proves, but not
        // what THIS assertion measures. A fresh request isolates the two
        // measurements exactly as two real, separate page loads would be.
        $this->app->instance('request', \Illuminate\Http\Request::create('/dashboard'));

        // Every Business-scoped Available feature, more than double the three
        // the menu gates on today.
        $many = ['crm', 'conversations', 'automations', 'website_generation', 'google_business_profile_module'];
        $doubled = $this->countQueriesResolving($workspace, $business, $many, $customer->user_id);

        $this->assertSame(
            $baseline,
            $doubled,
            "Resolving " . count($many) . " features cost {$doubled} queries against {$baseline} for " . count($three) . '.',
        );
    }

    public function test_the_account_frame_costs_no_entitlement_queries(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $entitlements = MenuEntitlements::none();

        $this->assertSame(0, $count, 'An Account frame has no Business to evaluate and must query nothing.');
        $this->assertFalse($entitlements->allows('automations'));
        $this->assertFalse($entitlements->evaluated);
    }

    /**
     * @param  array<int, string>  $featureKeys
     */
    private function countQueriesResolving(Workspace $workspace, Business $business, array $featureKeys, int $actorUserId): int
    {
        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        MenuEntitlements::forBusiness(
            app(EntitlementManager::class),
            $workspace,
            $business,
            $featureKeys,
            $actorUserId,
        );

        return $count;
    }

    // =================================================================
    // §13 #10, #11 — URLs and nested active state
    // =================================================================

    public function test_every_emitted_menu_url_resolves_to_a_registered_route(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            foreach ($this->menuLinks($this->home()->assertOk()->getContent()) as $url) {
                $this->assertUrlMatchesARegisteredRoute($url);
            }
        }
    }

    /**
     * §13 #11, re-homed by the Settings hub — an Advanced screen (Settings →
     * Advanced → Keywords) is reached from the hub, not a nested menu, so the
     * one Settings entry is what stays active: the customer never lands on a
     * page whose menu shows nowhere.
     */
    public function test_a_settings_screen_keeps_the_single_settings_entry_active(): void
    {
        // Two client accounts, so the Agency stands in its own account frame,
        // where Advanced belongs.
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $html = $this->get(route('customer.keywords.index'))->assertOk()->getContent();

        $this->assertContains('settings', $this->activeMenuKeys($html), 'Settings is active on its screens.');
        $this->assertNotContains('keywords', $this->menuKeys($html), 'The screen itself is a hub module, not a sidebar leaf.');
        $this->assertStringNotContainsString('has-sub', $this->sidebarHtml($html));
    }

    // =================================================================
    // §13 #21, #22, #23, #24, #25 — shell invariants
    // =================================================================

    public function test_no_raw_locale_key_renders_in_the_new_tree(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();

            $this->assertStringNotContainsString('locale.', $this->shellText($html));
        }
    }

    public function test_the_new_tree_renders_no_workspace_terminology(): void
    {
        foreach ([WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Business ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $text = $this->shellText($this->home()->assertOk()->getContent());

            $this->assertStringNotContainsString('Workspace', $text);
            $this->assertStringNotContainsString('Workspaces', $text);
        }
    }

    /**
     * Superseded by Customer Experience Redesign Slice 2B, stated rather than
     * hidden. Slice 2A locked the Inbox target to `customer.chatbox.index`
     * (`/chat-box`) because moving it was 2B's job. 2B has now moved it: the
     * Inbox entry targets the selected Business's own inbox, and the flat
     * route survives only as a GET compatibility redirector at the same URI.
     */
    public function test_the_chatbox_route_and_uri_are_unchanged(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Inbox Co', 'Inbox Account');
        $this->authenticateAs($customer);

        $canonical = app('router')->getRoutes()->getByName('customer.workspaces.businesses.conversations.index');

        $this->assertNotNull($canonical);
        $this->assertSame('workspaces/{workspaceUid}/businesses/{businessUid}/conversations', $canonical->uri());

        $this->assertContains(
            route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]),
            $this->menuLinks($this->home()->assertOk()->getContent()),
            'The Inbox entry targets the selected Business\'s inbox.',
        );

        // The old URI is kept, as a redirector into that same inbox.
        $legacy = app('router')->getRoutes()->getByName('customer.chatbox.index');

        $this->assertNotNull($legacy);
        $this->assertSame('chat-box', $legacy->uri());
        $this->assertSame(['GET'], array_values(array_diff($legacy->methods(), ['HEAD'])));
        $this->get('/chat-box')->assertRedirect(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]));
    }

    /**
     * §13 #5 — an Agency staff member scoped to one Business gets the
     * Business frame and none of the account-level management entries.
     */
    public function test_agency_staff_scoped_to_one_business_gets_no_account_management_entries(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($staff);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        // Scoped to exactly one Business, so the Business frame.
        $this->assertContains('conversations', $keys);

        $hub = $this->settingsHubModuleKeys($this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent());

        foreach (['accounts', 'prospecting', 'team', 'plan', 'account-details', 'messaging-provider'] as $accountLevel) {
            $this->assertNotContains($accountLevel, array_merge($keys, $hub), "Scoped staff must not see [{$accountLevel}].");
        }
    }

    /**
     * §13 #6 — a restricted Business actor keeps the product entries and
     * loses Settings → Account; Billing follows canManageBilling().
     */
    public function test_a_restricted_business_actor_sees_no_account_entry(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($staff);

        $this->assertContains('contacts', $this->menuKeys($this->home()->assertOk()->getContent()), 'The product surface stays.');

        $hub = fn () => $this->settingsHubModuleKeys($this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent());
        $keys = $hub();

        $this->assertNotContains('team', $keys, 'A non-manager gets no Team.');
        $this->assertNotContains('plan', $keys, 'Nor Plan & subscription.');

        // The owner of the same Business does get both.
        $this->authenticateAs($owner);
        $ownerKeys = $hub();
        $this->assertContains('team', $ownerKeys);
        $this->assertContains('plan', $ownerKeys);
    }

    /**
     * §13 #8 — view-as narrowing is preserved exactly.
     *
     * §10's operative rule is that narrowing "stays with
     * ViewAsRouteClassification::allowsMenuEntry()", and this slice changes
     * neither that class nor its classifications. So the assertion below is
     * what that classification actually decides, not a re-derivation of it.
     *
     * ONE DELIBERATE NUANCE, worth stating rather than hiding. §10's prose
     * lists "billing" among the entries that disappear, but the merged
     * classification marks `…businesses.usage-billing.show` as
     * BusinessScoped — a per-Business surface an agency legitimately manages
     * on behalf of the client it is viewing — while every ACCOUNT-level money
     * and staff surface (subscriptions, the account page, the provider
     * leaves) is blocked. That distinction is the classification's own, it
     * predates this slice, and §10 tells 2A to preserve it rather than
     * relitigate it here.
     */
    public function test_view_as_client_narrowing_removes_the_account_level_entries(): void
    {
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $viewing = $this->startViewAs($workspace, $business, 'Slice 2A navigation check.');
        $viewing->assertRedirect();

        $keys = array_merge(
            $this->menuKeys($this->home()->assertOk()->getContent()),
            $this->settingsHubModuleKeys($this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent()),
        );

        foreach (['plan', 'team', 'account-details', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $sensitive) {
            $this->assertNotContains($sensitive, $keys, "[{$sensitive}] must be absent while viewing as a client.");
        }

        // The client's own product surface is still there — narrowing, not
        // blanking.
        $this->assertContains('contacts', $keys);
    }

    /**
     * The nuance above, asserted directly against the classification so a
     * future change to it cannot silently alter the menu without a failure
     * naming the reason.
     */
    public function test_account_level_money_surfaces_stay_blocked_by_the_view_as_classification(): void
    {
        $classification = app(\App\Library\ViewAs\ViewAsRouteClassification::class);

        foreach (['customer.subscriptions.index', 'customer.workspaces.show', 'customer.numbers.index'] as $accountLevel) {
            $this->assertFalse(
                $classification->allowsMenuEntry($accountLevel),
                "[{$accountLevel}] is account-level and must stay blocked while viewing.",
            );
        }

        $this->assertTrue(
            $classification->allowsMenuEntry('customer.workspaces.businesses.usage-billing.show'),
            'Per-Business usage billing is Business-scoped by the merged classification — preserved, not changed by 2A.',
        );
    }

    /**
     * §13 #16 — hiding is not authorization. The same actor requesting the
     * hidden destination directly is still refused by the controller.
     */
    public function test_a_hidden_entitlement_gated_destination_is_still_refused_on_direct_request(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        // Precondition: Core has no Get found entry.
        $this->assertNotContains('gbp', $this->menuKeys($this->home()->assertOk()->getContent()));

        $response = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'The controller must stay independently fail-closed; the menu is only the visible half.',
        );
    }

    /** §13 #23 — both shells render the same tree. */
    public function test_the_vertical_and_horizontal_shells_both_render_the_new_tree(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        // The vertical sidebar.
        foreach (['conversations', 'settings'] as $key) {
            $this->assertContains($key, $this->menuKeys($html));
        }

        // The horizontal shell renders from the same builder, so the same
        // keys appear in the document outside the sidebar region too.
        $horizontal = view('panels.horizontalMenu')->render();

        foreach (['Conversations', 'Settings'] as $label) {
            $this->assertStringContainsString($label, $horizontal, "The horizontal shell must render [{$label}].");
        }

        $this->assertStringNotContainsString('>Inbox<', $horizontal);

        foreach (['Send', 'Campaigns'] as $legacyOutbound) {
            $this->assertStringNotContainsString('>' . $legacyOutbound . '<', $horizontal, "The horizontal shell must not render [{$legacyOutbound}].");
        }

        $this->assertStringNotContainsString('Developers', $horizontal);
    }

    public function test_no_bottom_navigation_markup_is_added_to_the_shell(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        foreach (['bottom-nav', 'mobile-tab-bar', 'nav-bottom', 'bottom-navigation'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, "Slice 2A adds no mobile navigation ({$marker}).");
        }
    }
}
