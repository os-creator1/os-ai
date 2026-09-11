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
        'messages', 'inbox', 'send', 'campaigns', 'contacts',
        'automations', 'website', 'gbp', 'analytics', 'business', 'blocked-numbers',
    ];

    // =================================================================
    // §13 #1, #2 — the exact Business trees
    // =================================================================

    public function test_a_core_business_gets_the_contracted_tree_without_get_found(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['home', 'messages', 'inbox', 'contacts', 'automations', 'website', 'analytics', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "A Core Business must offer [{$expected}].");
        }

        foreach (['send', 'campaigns'] as $legacyOutbound) {
            $this->assertNotContains($legacyOutbound, $keys, "Messages is Inbox only: no [{$legacyOutbound}].");
        }

        // The D-20 exemplar: Core's catalog excludes the GBP module.
        $this->assertNotContains('gbp', $keys, 'Core has no Get found.');
    }

    public function test_a_growth_business_gets_get_found_because_its_plan_includes_it(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $keys = $this->menuKeys($html);

        foreach (['home', 'messages', 'inbox', 'contacts', 'automations', 'website', 'gbp', 'analytics', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "A Growth Business must offer [{$expected}].");
        }

        foreach (['send', 'campaigns'] as $legacyOutbound) {
            $this->assertNotContains($legacyOutbound, $keys, "Messages is Inbox only: no [{$legacyOutbound}].");
        }

        $this->assertStringContainsString('Get found', $this->shellText($html));
    }

    /**
     * Messages is Inbox only: the legacy outbound Send and Campaigns pages
     * are no longer customer destinations (their routes stay registered).
     */
    public function test_the_messages_group_carries_inbox_only(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        $messages = array_search('messages', $keys, true);
        $contacts = array_search('contacts', $keys, true);

        $this->assertNotFalse($messages);
        $this->assertSame(['inbox'], array_slice($keys, $messages + 1, $contacts - $messages - 1), 'Messages holds Inbox and nothing else.');
    }

    // =================================================================
    // §13 #3, #4 — the Account frame and the Advanced authority
    // =================================================================

    public function test_an_agency_owner_gets_the_account_tree_with_advanced(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['home', 'accounts', 'prospecting', 'settings', 'plan', 'advanced', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $expected) {
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

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['advanced', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "An agency-wide Admin is not the owner and must not see [{$forbidden}].");
        }

        // The owner of the same Workspace still does — the rule narrows by
        // ownership, it does not switch the surface off.
        $this->authenticateAs($owner);
        $this->assertContains('advanced', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_the_advanced_group_also_requires_the_manage_advanced_provider_permission(): void
    {
        [$agency] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        // Ownership alone is never sufficient — the permission is stacked.
        $withoutPermission = array_values(array_filter(
            $this->allCustomerPermissions(),
            static fn (string $permission): bool => $permission !== 'manage_advanced_provider',
        ));

        $this->authenticateAs($agency, $withoutPermission);

        $this->assertNotContains('advanced', $this->menuKeys($this->home()->assertOk()->getContent()));
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
     * §13 #11 — Advanced now sits three levels deep in the Account frame
     * (Settings → Advanced → leaf). An active leaf must open BOTH ancestors,
     * or the customer lands on a page whose menu looks collapsed.
     */
    public function test_a_third_level_active_leaf_opens_both_of_its_ancestors(): void
    {
        [$agency] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $html = $this->get(route('customer.keywords.index'))->assertOk()->getContent();
        $active = $this->activeMenuKeys($html);

        $this->assertContains('keywords', $active, 'The leaf itself is active.');

        // The two ancestors are open — MenuItem::hasActiveChild() recurses,
        // and the component uses it to expand.
        $sidebar = $this->sidebarHtml($html);

        foreach (['settings', 'advanced'] as $ancestor) {
            $this->assertMatchesRegularExpression(
                '/<li class="[^"]*(open|active)[^"]*" data-nav-key="' . $ancestor . '"/',
                $sidebar,
                "[{$ancestor}] must be expanded when a descendant is active.",
            );
        }
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
        $this->assertContains('messages', $keys);

        foreach (['accounts', 'prospecting', 'team', 'plan', 'advanced'] as $accountLevel) {
            $this->assertNotContains($accountLevel, $keys, "Scoped staff must not see [{$accountLevel}].");
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

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->assertContains('contacts', $keys, 'The product surface stays.');
        $this->assertNotContains('team', $keys, 'A non-manager gets no Account entry.');
        $this->assertNotContains('plan', $keys, 'Nor Plan & subscription.');

        // The owner of the same Business does get both.
        $this->authenticateAs($owner);
        $ownerKeys = $this->menuKeys($this->home()->assertOk()->getContent());
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

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['plan', 'team', 'advanced', 'messaging-provider', 'sender-ids', 'numbers', 'keywords'] as $sensitive) {
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
        foreach (['messages', 'inbox'] as $key) {
            $this->assertContains($key, $this->menuKeys($html));
        }

        // The horizontal shell renders from the same builder, so the same
        // keys appear in the document outside the sidebar region too.
        $horizontal = view('panels.horizontalMenu')->render();

        foreach (['Messages', 'Inbox'] as $label) {
            $this->assertStringContainsString($label, $horizontal, "The horizontal shell must render [{$label}].");
        }

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
