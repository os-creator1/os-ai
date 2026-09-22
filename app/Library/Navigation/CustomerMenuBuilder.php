<?php

namespace App\Library\Navigation;

use App\Http\Controllers\Customer\Business\CrmOpportunitiesController;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The context-aware, authorization-driven customer menu (contract §8.2,
 * §8.3, §8.4, §8.6). Replaces the static customer branch of
 * Helper::menuData() for RENDERING; the legacy array is retained only as
 * data for its remaining consumers and is no longer rendered to customers.
 *
 * Rules enforced by construction:
 *  - an entry exists only when its route is registered (T-NAV-3);
 *  - an entry exists only when the actor holds a permission that reaches
 *    it, using the same Gate the routes enforce (T-NAV-2); visibility is
 *    never the authorization boundary (S-3);
 *  - Business-scoped entries carry the SELECTED Workspace/Business uids,
 *    so no link ever guesses across Workspaces;
 *  - the active state is derived from the current route NAME and the
 *    resolved frame, never from the URL's leading segment (§8.5, T-CTX-5);
 *  - the Account frame and the Business frame never share entries.
 */
final class CustomerMenuBuilder
{
    private const CONTACT_PERMISSIONS = [
        'view_contact_group', 'create_contact_group', 'update_contact_group', 'delete_contact_group',
        'view_contact', 'create_contact', 'update_contact', 'delete_contact',
    ];

    private const BLACKLIST_PERMISSIONS = ['view_blacklist', 'create_blacklist', 'update_blacklist', 'delete_blacklist'];

    /**
     * The screens a Business's Settings hub leads to. The single Settings
     * entry is active on the hub and on every one of them.
     */
    private const BUSINESS_SETTINGS_ROUTES = [
        'customer.workspaces.businesses.settings.',
        'customer.business.',
        'customer.workspaces.businesses.locations.',
        'customer.workspaces.businesses.text-messaging.',
        'customer.workspaces.businesses.usage-billing.',
        'customer.workspaces.plan.',
        'customer.workspaces.team.',
    ];

    /** The screens an account's Settings hub leads to (see BUSINESS_SETTINGS_ROUTES). */
    private const ACCOUNT_SETTINGS_ROUTES = [
        'customer.workspaces.settings.',
        'customer.workspaces.plan.',
        'customer.workspaces.team.',
        'customer.blacklists.',
        'customer.channels.',
        'customer.senderid.',
        'customer.numbers.',
        'customer.keywords.',
    ];

    /**
     * One plain sentence per Settings hub module, keyed like the module.
     */
    public const SETTINGS_DESCRIPTIONS = [
        'business-details' => 'Name, contact details and what the business does.',
        'locations' => 'Addresses and the areas this business serves.',
        'text-messaging' => 'This business’s number and whether texting is ready.',
        'usage-billing' => 'Balance, top-ups, payment method and spending limits.',
        'plan' => 'What your plan includes and your subscription.',
        'team' => 'Who can work here, their role and what they can open.',
        'account-details' => 'Agency account name, client accounts and who pays for each.',
        'blocked-numbers' => 'Numbers that are never messaged.',
        'messaging-provider' => 'Connect your own messaging provider.',
        'sender-ids' => 'Sender names used on outgoing messages.',
        'numbers' => 'Phone numbers on this account.',
        'keywords' => 'Words people can text in to reach you.',
    ];

    /**
     * Slice 2A §6.2 — the Business-scoped features that gate a menu entry.
     *
     * Deliberately short. `crm` is listed for the Opportunities entry — the
     * CRM sales board, which its HTTP boundary gates on `crm` — but Contacts
     * is still NOT gated on it: whether any tier's catalog genuinely excludes
     * CRM cannot be established from code, and hiding Contacts from a tier
     * that pays for it is a worse failure than showing it to one that does not.
     * `conversations` IS gated since Slice 2B (§16): Inbox now lives on the
     * Business-scoped conversations route, so there is always a Business to
     * evaluate it against. It has to be listed here, not only checked below —
     * the snapshot answers only what it was asked, and MenuEntitlements fails
     * closed on anything else, so an unlisted key would hide Inbox for
     * everyone. The query cost does not change: every read is bulk.
     */
    public const ENTITLEMENT_GATED_FEATURES = [
        'automations',
        'website_generation',
        'google_business_profile_module',
        'conversations',
        'crm',
        // Calendar (Contract 15 §3.3/§12.D). This list is NAV gating only and is
        // never the security gate: every Calendar route independently carries
        // the entitlement decision (§6). Omitting the key here would hide the
        // entry forever once the feature is entitled.
        'calendar',
        // Packages & Products (Contract 16 §12.E). Omitting this line would
        // not merely fall back to a slower query: entitled() answers from the
        // bulk snapshot, which fails closed on any key not listed here, so an
        // unlisted key would silently hide the entry for every account forever.
        'packages_products',
        // Not a menu entry: AI-3's Business Home "What we notice" line reads
        // this answer from the same one bulk snapshot, so checking it costs
        // the page no entitlement query of its own (§16).
        'ai_coo_basic',
    ];

    /**
     * The decisions EntitlementManager already made for this request. Never
     * a policy of its own — see MenuEntitlements.
     */
    private MenuEntitlements $entitlements;

    /**
     * While a View-as-client session is active only entries whose route is
     * reachable inside the viewed Business are rendered (Correction Round 1):
     * the same closed classification the middleware enforces.
     */
    private bool $viewingAsClient = false;

    public function __construct(private readonly ViewAsRouteClassification $viewAsRoutes)
    {
    }

    /**
     * @return array<int, MenuItem>
     */
    public function build(CustomerContext $context, User $user, ?MenuEntitlements $entitlements = null): array
    {
        $current = (string) Route::currentRouteName();
        $this->viewingAsClient = $context->isViewingAsClient();

        // Fail closed. A caller that forgets the snapshot loses the
        // entitlement-gated entries rather than showing them to everyone —
        // the opposite default would make §6.1 unenforceable by accident.
        $this->entitlements = $entitlements ?? MenuEntitlements::none();

        return $context->isBusinessFrame()
            ? $this->businessFrame($context, $user, $current)
            : $this->accountFrame($context, $user, $current);
    }

    /**
     * Contract §8.2 — the default Core/Growth experience, and the frame an
     * Agency actor enters after choosing a client account.
     *
     * @return array<int, MenuItem>
     */
    private function businessFrame(CustomerContext $context, User $user, string $current): array
    {
        $workspaceUid = $context->selectedWorkspace?->uid;
        $businessUid = $context->selectedBusiness?->uid;
        $scoped = [$workspaceUid, $businessUid];

        $items = [];

        $items[] = $this->item($user, 'home', 'Home', 'home', ['access_backend'], 'user.home', [], $current, ['user.home']);

        // No separate Advisor entry (owner decision): the Business Home already
        // carries the next best move and the way into its recommendations, and
        // customer-facing Opportunities belong to the CRM sales module. Two
        // primary modules for one idea would confuse. The Advisor's routes,
        // pages and Home links are untouched — only the sidebar entry went.

        // Conversations — ONE destination, the selected Business's own
        // conversations (owner decision: no "Messages → Inbox" group around a
        // single child). Shown only when that Business is entitled to
        // Conversations (Slice 2B §16). The legacy outbound surfaces (Send,
        // Campaigns) are not a local-business workflow and are not offered;
        // their routes stay registered. Agency outbound prospecting lives in
        // the Agency account frame (Prospecting), never in a client Business.
        $items[] = $this->entitled('conversations', $this->item($user, 'conversations', 'Conversations', 'message-square', ['chat_box'], 'customer.workspaces.businesses.conversations.index', $scoped, $current, [
            'customer.workspaces.businesses.conversations.',
        ]));

        // Contacts opens the people first ("All contacts"); groups are its
        // secondary tab and keep the entry active too.
        $items[] = $this->item($user, 'contacts', 'Contacts', 'users', self::CONTACT_PERMISSIONS, 'customer.workspaces.businesses.people.index', $scoped, $current, [
            'customer.workspaces.businesses.people.', 'customer.workspaces.businesses.contacts.', 'customer.workspaces.businesses.contact.', 'customer.contacts.', 'customer.contact.',
        ]);

        // Opportunities — the CRM sales board of the selected Business
        // (App\Library\Crm; NOT the AI COO Advisor, which has no sidebar entry).
        // Business frame only: an Agency reaches it after choosing a client
        // Business. Offered exactly when the CRM boundary would let the actor
        // in — the `crm` entitlement and the board's own read permission.
        $items[] = $this->entitled('crm', $this->item($user, 'opportunities', 'Opportunities', 'kanban', [CrmOpportunitiesController::VIEW_PERMISSION], 'customer.workspaces.businesses.crm.board', $scoped, $current, [
            'customer.workspaces.businesses.crm.',
        ]));

        // Calendar — the authenticated day/week schedule (Contract 15 §12.D). Offered
        // only when the Business is entitled to it; while PlatformFeature::Calendar is
        // Planned that answer is always no, so the entry is absent. The route behind it
        // is separately gated and would 404 regardless of what the menu shows.
        $items[] = $this->entitled('calendar', $this->item($user, 'calendar', 'Calendar', 'calendar', ['access_backend'], 'customer.workspaces.businesses.calendar.index', $scoped, $current, [
            'customer.workspaces.businesses.calendar.',
        ]));

        $items[] = $this->entitled('automations', $this->item($user, 'automations', 'Automations', 'cpu', ['automations'], 'customer.workspaces.businesses.automations.workflows.index', $scoped, $current, [
            'customer.workspaces.businesses.automations.', 'customer.automations.',
        ]));
        $items[] = $this->entitled('website_generation', $this->item($user, 'website', 'Website', 'globe', ['website'], 'customer.workspaces.businesses.website.show', $scoped, $current, [
            'customer.workspaces.businesses.website.', 'customer.website.',
        ]));
        $items[] = $this->entitled('google_business_profile_module', $this->item($user, 'gbp', 'Get found', 'map-pin', ['view_google_business_profile'], 'customer.workspaces.businesses.gbp.index', $scoped, $current, [
            'customer.workspaces.businesses.gbp.', 'customer.gbp.',
        ]));
        // Packages & Products — the Business-wide catalog (Contract 16 §12.E).
        // Offered exactly when the catalog boundary would let the actor in on
        // the first two gates: the `packages_products` capability (item()) and
        // the entitlement (entitled()). Visibility is NEVER authorization —
        // every catalog route re-runs the full §6 chain itself, so hiding or
        // showing this entry changes nothing about what a request can do.
        $items[] = $this->entitled('packages_products', $this->item($user, 'packages_products', 'Packages & Products', 'package', ['packages_products'], 'customer.workspaces.businesses.catalog.index', $scoped, $current, [
            'customer.workspaces.businesses.catalog.',
        ]));
        $items[] = $this->item($user, 'analytics', 'Results', 'bar-chart-2', ['view_reports'], 'customer.workspaces.businesses.analytics.overview', $scoped, $current, [
            'customer.workspaces.businesses.analytics.', 'customer.analytics.',
        ]);

        // Settings — ONE destination (owner decision). Nothing expands under it
        // in the sidebar: it opens the Settings hub, a page of cards for the
        // configuration a Business does not need day to day, each linking to
        // its own screen. The entry stays active on every one of those screens.
        if ($this->businessSettingsSections($context, $user, $current) !== []) {
            // An Agency client Business's hub has no Plan or Team (they are the
            // Agency account's), so those screens do not light it up.
            $activeRoutes = $context->selectedWorkspace?->isAgency()
                ? array_values(array_diff(self::BUSINESS_SETTINGS_ROUTES, ['customer.workspaces.plan.', 'customer.workspaces.team.']))
                : self::BUSINESS_SETTINGS_ROUTES;

            $items[] = $this->item($user, 'settings', 'Settings', 'settings', ['access_backend'], 'customer.workspaces.businesses.settings.show', $scoped, $current, $activeRoutes);
        }

        return array_values(array_filter($items));
    }

    /**
     * Contract §8.3 — the Agency (or multi-Business) account frame. Shown
     * whenever no Business is selected.
     *
     * @return array<int, MenuItem>
     */
    private function accountFrame(CustomerContext $context, User $user, string $current): array
    {
        $items = [];

        $items[] = $this->item($user, 'home', 'Home', 'home', ['access_backend'], 'user.home', [], $current, ['user.home']);

        $workspace = $context->selectedWorkspace;

        if ($context->requiresWorkspaceSelection() || $workspace === null) {
            $items[] = $this->item($user, 'accounts', $context->hasMultipleWorkspaces() ? 'Choose an account' : $context->businessesNoun(), 'briefcase', ['access_backend'], 'customer.workspaces.index', [], $current, [
                'customer.workspaces.index', 'customer.workspaces.show', 'customer.workspaces.additional-business-slots.',
            ]);
        } else {
            $items[] = $this->item($user, 'accounts', $context->businessesNoun(), 'briefcase', ['access_backend'], 'customer.workspaces.show', [$workspace->uid], $current, [
                'customer.workspaces.show', 'customer.workspaces.index', 'customer.workspaces.additional-business-slots.',
            ]);
        }

        if ($context->isAgency()) {
            $items[] = $this->item($user, 'prospecting', 'Prospecting', 'target', ['access_backend'], 'customer.prospecting.index', [], $current, [
                'customer.prospecting.', 'customer.workspaces.prospecting.',
            ]);
        }

        // Settings — the account's own hub, one destination, as in the
        // Business frame. With several accounts and none chosen there is no
        // account to configure, so there is no entry.
        $account = $context->frameWorkspace();

        if ($account !== null && $this->accountSettingsSections($context, $account, $user, $current) !== []) {
            $items[] = $this->item($user, 'settings', 'Settings', 'settings', ['access_backend'], 'customer.workspaces.settings.show', [$account->uid], $current, self::ACCOUNT_SETTINGS_ROUTES);
        }

        return array_values(array_filter($items));
    }

    /**
     * The Settings hub's sections: for the Business the context selected, or
     * — given $account — for that account itself (the Agency account's own
     * settings). Every module is gated exactly as a menu entry is: its route
     * must exist, the actor must hold a permission that reaches it, and it
     * must be reachable while viewing as a client.
     *
     * @return array<int, array{key: string, title: string, items: array<int, MenuItem>}>
     */
    public function settingsSections(CustomerContext $context, User $user, ?MenuEntitlements $entitlements = null, ?WorkspaceCandidate $account = null): array
    {
        $current = (string) Route::currentRouteName();
        $this->viewingAsClient = $context->isViewingAsClient();
        $this->entitlements = $entitlements ?? MenuEntitlements::none();

        return $account !== null
            ? $this->accountSettingsSections($context, $account, $user, $current)
            : $this->businessSettingsSections($context, $user, $current);
    }

    /**
     * A Business's Settings hub (owner decision).
     *
     *   Business setup     Business details, Locations
     *   Communication      Text messaging
     *   Billing & team     Billing, Plan & subscription, Team
     *
     * The Core/Growth account is not a customer-managed object of its own:
     * there is no "Account" module, and its plan and team sit here beside the
     * Business they serve. An Agency client Business is different — its plan
     * and team belong to the Agency account and are configured in the Agency
     * account's own Settings, never repeated inside a client Business. Only
     * the client's own Billing stays here.
     *
     * Blocked numbers (the legacy, user-scoped blacklist) is not a Business
     * setting and is not offered here; see accountSettingsSections().
     *
     * @return array<int, array{key: string, title: string, items: array<int, MenuItem>}>
     */
    private function businessSettingsSections(CustomerContext $context, User $user, string $current): array
    {
        $workspace = $context->selectedWorkspace;
        $business = $context->selectedBusiness;

        if ($workspace === null || $business === null) {
            return [];
        }

        $scoped = [$workspace->uid, $business->uid];
        $agencyClient = $workspace->isAgency();

        $setup = [];

        if ($business->customerId === $context->userId && $business->isPrimary) {
            // BusinessController@edit resolves the customer's PRIMARY
            // Business; it is only offered when that is the selected one.
            $setup[] = $this->item($user, 'business-details', 'Business details', 'briefcase', ['access_backend'], 'customer.business.edit', [], $current, ['customer.business.']);
        }

        // Customer Experience Slice 1A — the selected Business's physical
        // locations. A location is part of the Business, never an account or
        // a switcher level; the destination enforces its own tenancy.
        $setup[] = $this->item($user, 'locations', 'Locations', 'map', ['access_backend'], 'customer.workspaces.businesses.locations.index', $scoped, $current, [
            'customer.workspaces.businesses.locations.',
        ]);

        // Owner product decision — the plain-language, read-only status
        // surface every tier sees (Core, Growth, and an Agency Business using
        // managed transport).
        $communication = [
            $this->item($user, 'text-messaging', 'Text messaging', 'message-circle', ['view_numbers'], 'customer.workspaces.businesses.text-messaging.show', $scoped, $current, [
                'customer.workspaces.businesses.text-messaging.',
            ]),
        ];

        $billingAndTeam = [];

        if ($context->canManageBilling()) {
            $billingAndTeam[] = $this->item($user, 'usage-billing', 'Billing', 'credit-card', ['access_backend'], 'customer.workspaces.businesses.usage-billing.show', $scoped, $current, [
                'customer.workspaces.businesses.usage-billing.',
            ]);
        }

        if (! $agencyClient && $context->canManageWorkspace()) {
            if ((bool) $user->is_customer) {
                // This account's own AI Business OS plan (Workspace plan
                // domain) — never the inherited SMS plans/subscriptions page.
                $billingAndTeam[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.workspaces.plan.show', [$workspace->uid], $current, [
                    'customer.workspaces.plan.',
                ]);
            }

            $billingAndTeam[] = $this->teamItem($user, $workspace->uid, $current);
        }

        return $this->sections([
            'business-setup' => ['Business setup', $setup],
            'communication' => ['Communication', $communication],
            // Plain words: "Account" is not a thing a Core or Growth customer manages.
            'billing-team' => [$agencyClient ? 'Billing' : 'Billing & team', $billingAndTeam],
        ]);
    }

    /**
     * An account's own Settings hub — above all the Agency account's.
     *
     *   Agency account  Agency account details, Plan & subscription, Team
     *   Outreach        Blocked numbers
     *   Advanced        the provider-level surfaces, owner only (§8.6)
     *
     * Agency account details is the Agency account page (its name, its client
     * accounts and who pays for each). A Core or Growth account has no such
     * module: it is not a customer-managed object, and its plan and team live
     * in its Business's Settings. This hub is only reached for one when it
     * has no Business to open yet.
     *
     * Blocked numbers is the legacy, user-scoped blacklist, kept for Agencies
     * exactly as it is — not repurposed. SEAM: an Agency's real need is an
     * outreach suppression list, which belongs here as Prospecting →
     * Suppression list; when it exists it replaces this module.
     *
     * @return array<int, array{key: string, title: string, items: array<int, MenuItem>}>
     */
    private function accountSettingsSections(CustomerContext $context, WorkspaceCandidate $account, User $user, string $current): array
    {
        $manages = $account->canManage();
        $accountItems = [];

        if ($manages && $account->isAgency()) {
            $accountItems[] = $this->item($user, 'account-details', 'Agency account details', 'user-check', ['access_backend'], 'customer.workspaces.show', [$account->uid], $current, [
                'customer.workspaces.show',
            ]);
        }

        if ($manages && (bool) $user->is_customer) {
            $accountItems[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.workspaces.plan.show', [$account->uid], $current, [
                'customer.workspaces.plan.',
            ]);
        }

        if ($manages) {
            $accountItems[] = $this->teamItem($user, $account->uid, $current);
        }

        $outreach = [];

        if ($account->isAgency()) {
            $outreach[] = $this->item($user, 'blocked-numbers', 'Blocked numbers', 'shield', self::BLACKLIST_PERMISSIONS, 'customer.blacklists.index', [], $current, ['customer.blacklists.']);
        }

        return $this->sections([
            'account' => [$account->isAgency() ? 'Agency account' : 'Account', $accountItems],
            'outreach' => ['Outreach', $outreach],
            'advanced' => ['Advanced', $this->advancedItems($context, $user, $current, 'customer.channels.index', [])],
        ]);
    }

    /**
     * Settings → Team: the ONE customer destination for who works in this
     * account — members, their role, and which Businesses each may open
     * (the account membership, RFC-003; an Agency assigns client-account
     * access here). Offered only to the account's owner and active Admins,
     * the same authority the member actions enforce — and never to a team
     * member who is currently signed in AS the account holder.
     *
     * The legacy delegated-access surface (customer.sub_accounts.*, "Team
     * members") is deliberately NOT offered any more: two team destinations
     * is the duplication this replaces. Its routes, models and permissions are
     * untouched and still reachable directly, pending its separately
     * contracted retirement (navigation redesign §12.3).
     */
    private function teamItem(User $user, string $workspaceUid, string $current): ?MenuItem
    {
        if (session()->has('parent_user_id') && session()->has('temp_user_id')) {
            return null;
        }

        return $this->item($user, 'team', 'Team', 'users', ['access_backend'], 'customer.workspaces.team.show', [$workspaceUid], $current, ['customer.workspaces.team.']);
    }

    /**
     * @param  array<string, array{0: string, 1: array<int, MenuItem|null>}>  $definitions
     * @return array<int, array{key: string, title: string, items: array<int, MenuItem>}>
     */
    private function sections(array $definitions): array
    {
        $sections = [];

        foreach ($definitions as $key => [$title, $items]) {
            $items = array_values(array_filter($items));

            if ($items !== []) {
                $sections[] = ['key' => $key, 'title' => $title, 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Contract §8.6 — provider-level surfaces are Agency owner/admin only,
     * each still permission-gated, and never part of the Core/Growth menu.
     *
     * @param  array<int, string|null>  $channelsParameters
     * @return array<int, MenuItem>
     */
    private function advancedItems(CustomerContext $context, User $user, string $current, string $channelsRoute, array $channelsParameters): array
    {
        if (! $this->hasAdvancedProviderAccess($context, $user)) {
            return [];
        }

        // Developers is NOT here any more (§8). Its six routes stay
        // registered and directly reachable — this slice deletes no route,
        // controller, API key or webhook configuration; physical removal
        // belongs to retention slice 15. Only the menu entry goes.
        $children = array_values(array_filter([
            $this->item($user, 'messaging-provider', 'Messaging provider', 'link', ['view_numbers'], $channelsRoute, $channelsParameters, $current, [
                'customer.workspaces.businesses.channels.', 'customer.channels.',
            ]),
            $this->item($user, 'sender-ids', 'Sender identities', 'book', ['view_sender_id'], 'customer.senderid.index', [], $current, ['customer.senderid.']),
            $this->item($user, 'numbers', 'Numbers', 'phone', ['view_numbers'], 'customer.numbers.index', [], $current, ['customer.numbers.']),
            $this->item($user, 'keywords', 'Keywords', 'hash', ['view_keywords'], 'customer.keywords.index', [], $current, ['customer.keywords.']),
        ]));

        return $children;
    }

    /**
     * Slice 2A §7.1 — the Advanced group's authority, taken from Slice 3's
     * FINAL merged rule rather than restated.
     *
     * Slice 3 narrowed this surface to authoritative Workspace OWNERSHIP.
     * `config/customer-permissions.php` states it outright — the permission
     * "is necessary but never sufficient: the relocated advanced-settings
     * surface additionally requires authoritative Workspace OWNERSHIP
     * (WorkspaceCandidate::$isOwner), not canManage(), not plan tier, and
     * not admin membership" — and MessagingChannelsController's
     * hasAdvancedProviderAccess() enforces exactly that.
     *
     * So an agency-wide active Admin who is not the owner sees NO Advanced
     * group. Substituting canManageWorkspace() here would put a menu entry
     * in front of an actor the controller answers 404 to, which is the
     * discoverability failure §6.1 exists to prevent — in the direction that
     * wastes the customer's time rather than leaking data, but a defect
     * either way.
     *
     * The controller additionally checks Business status and entitlement per
     * request; the menu mirrors the actor-level half, and the controller
     * stays independently fail-closed regardless (§6.1).
     */
    private function hasAdvancedProviderAccess(CustomerContext $context, User $user): bool
    {
        $workspace = $context->frameWorkspace();

        if ($workspace === null || ! $workspace->isAgency() || ! $workspace->isActive) {
            return false;
        }

        if (! $workspace->isOwner) {
            return false;
        }

        return Gate::forUser($user)->allows('manage_advanced_provider');
    }

    /**
     * Drops an entry whose plan feature is not entitled.
     *
     * Absent, never disabled: a greyed row still advertises an action, and
     * the sidebar is the wrong place to sell an upgrade (§6.1). Plan and
     * upgrade explanation belong on the Plan and Account surfaces.
     */
    private function entitled(string $featureKey, ?MenuItem $item): ?MenuItem
    {
        if ($item === null) {
            return null;
        }

        return $this->entitlements->allows($featureKey) ? $item : null;
    }

    /**
     * Emits an entry only when its route exists and the actor may reach it.
     *
     * @param  array<int, string>  $permissions  any-of, the same strings the routes gate on
     * @param  array<int, string|null>  $parameters
     * @param  array<int, string>  $activePrefixes  route-name prefixes (or exact names) marking the entry active
     */
    private function item(
        User $user,
        string $key,
        string $label,
        string $icon,
        array $permissions,
        string $routeName,
        array $parameters,
        string $current,
        array $activePrefixes,
    ): ?MenuItem {
        if (! Route::has($routeName)) {
            return null;
        }

        foreach ($parameters as $parameter) {
            if ($parameter === null || $parameter === '') {
                return null;
            }
        }

        if (! Gate::forUser($user)->any($permissions)) {
            return null;
        }

        if ($this->viewingAsClient && ! $this->viewAsRoutes->allowsMenuEntry($routeName)) {
            // Legacy user-scoped and account-level surfaces are outside the
            // viewed Business; the middleware would 404 them, so they are
            // not offered (T-NAV-2 holds while viewing).
            return null;
        }

        $active = false;

        foreach ($activePrefixes as $prefix) {
            if ($current === $prefix || ($current !== '' && str_ends_with($prefix, '.') && str_starts_with($current, $prefix))) {
                $active = true;
                break;
            }
        }

        return new MenuItem($key, $label, route($routeName, $parameters), $icon, $active);
    }
}
