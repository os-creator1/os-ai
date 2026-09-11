<?php

namespace App\Library\Navigation;

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

    private const OUTREACH_PERMISSIONS = ['sms_quick_send', 'sms_campaign_builder', 'mms_quick_send', 'mms_campaign_builder'];

    private const BLACKLIST_PERMISSIONS = ['view_blacklist', 'create_blacklist', 'update_blacklist', 'delete_blacklist'];

    /**
     * Slice 2A §6.2 — the Business-scoped features that gate a menu entry.
     *
     * Deliberately short. `crm` is Available and Business-scoped but Contacts
     * is NOT gated here: whether any tier's catalog genuinely excludes CRM
     * cannot be established from code, and hiding Contacts from a tier that
     * pays for it is a worse failure than showing it to one that does not.
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

        if (config('opportunity.enabled', false)) {
            $items[] = $this->item($user, 'advisor', 'Advisor', 'compass', ['access_backend'], 'customer.opportunities.index', [], $current, ['customer.opportunities.']);
        }

        // Messages — the one group that gathers what was three unrelated
        // top-level entries. Every entry in it is scoped to the selected
        // Business; Inbox joined them in Slice 2B.
        $messages = array_values(array_filter([
            // Slice 2B §16 — the selected Business's own inbox, shown only when
            // that Business is entitled to Conversations.
            $this->entitled('conversations', $this->item($user, 'inbox', 'Inbox', 'inbox', ['chat_box'], 'customer.workspaces.businesses.conversations.index', $scoped, $current, [
                'customer.workspaces.businesses.conversations.',
            ])),
            $this->item($user, 'send', 'Send', 'send', self::OUTREACH_PERMISSIONS, 'customer.workspaces.businesses.outreach.index', $scoped, $current, [
                'customer.workspaces.businesses.outreach.index', 'customer.outreach.index',
            ]),
            $this->item($user, 'campaigns', 'Campaigns', 'layers', self::OUTREACH_PERMISSIONS, 'customer.workspaces.businesses.outreach.campaigns', $scoped, $current, [
                'customer.workspaces.businesses.outreach.campaigns', 'customer.workspaces.businesses.outreach.campaigns.',
                'customer.outreach.campaigns.entry', 'customer.sms.', 'customer.mms.',
            ]),
        ]));

        if ($messages !== []) {
            $items[] = new MenuItem('messages', 'Messages', null, 'message-square', false, $messages);
        }

        // Contacts opens the people first ("All contacts"); groups are its
        // secondary tab and keep the entry active too.
        $items[] = $this->item($user, 'contacts', 'Contacts', 'users', self::CONTACT_PERMISSIONS, 'customer.workspaces.businesses.people.index', $scoped, $current, [
            'customer.workspaces.businesses.people.', 'customer.workspaces.businesses.contacts.', 'customer.workspaces.businesses.contact.', 'customer.contacts.', 'customer.contact.',
        ]);
        $items[] = $this->entitled('automations', $this->item($user, 'automations', 'Automations', 'cpu', ['automations'], 'customer.workspaces.businesses.automations.index', $scoped, $current, [
            'customer.workspaces.businesses.automations.', 'customer.automations.',
        ]));
        $items[] = $this->entitled('website_generation', $this->item($user, 'website', 'Website', 'globe', ['website'], 'customer.workspaces.businesses.website.show', $scoped, $current, [
            'customer.workspaces.businesses.website.', 'customer.website.',
        ]));
        $items[] = $this->entitled('google_business_profile_module', $this->item($user, 'gbp', 'Get found', 'map-pin', ['view_google_business_profile'], 'customer.workspaces.businesses.gbp.index', $scoped, $current, [
            'customer.workspaces.businesses.gbp.', 'customer.gbp.',
        ]));
        $items[] = $this->item($user, 'analytics', 'Results', 'bar-chart-2', ['view_reports'], 'customer.workspaces.businesses.analytics.overview', $scoped, $current, [
            'customer.workspaces.businesses.analytics.', 'customer.analytics.',
        ]);

        $settings = [];

        // Settings → Business — the Business's own record and the numbers it
        // refuses. Blocked numbers is presented here but its route stays
        // account-scoped; that mismatch is recorded debt (§9), not fixed by
        // moving a route this slice may not touch.
        $businessSettings = [];

        if ($context->selectedBusiness !== null
            && $context->selectedBusiness->customerId === $context->userId
            && $context->selectedBusiness->isPrimary) {
            // BusinessController@edit resolves the customer's PRIMARY
            // Business; it is only offered when that is the selected one.
            $businessSettings[] = $this->item($user, 'business-details', 'Business details', 'briefcase', ['access_backend'], 'customer.business.edit', [], $current, ['customer.business.']);
        }

        $businessSettings[] = $this->item($user, 'blocked-numbers', 'Blocked numbers', 'shield', self::BLACKLIST_PERMISSIONS, 'customer.blacklists.index', [], $current, ['customer.blacklists.']);

        $businessSettings = array_values(array_filter($businessSettings));

        if ($businessSettings !== []) {
            $settings[] = new MenuItem('business', 'Business', null, 'briefcase', false, $businessSettings);
        }

        if ($context->canManageBilling()) {
            $settings[] = $this->item($user, 'usage-billing', 'Billing', 'credit-card', ['access_backend'], 'customer.workspaces.businesses.usage-billing.show', $scoped, $current, [
                'customer.workspaces.businesses.usage-billing.',
            ]);
        }

        if ($context->canManageWorkspace() && $workspaceUid !== null) {
            // Team and member management are sections of this page; §3 forbids
            // a standalone Team leaf naming a destination that does not exist.
            $settings[] = $this->item($user, 'team', 'Account', 'user-check', ['access_backend'], 'customer.workspaces.show', [$workspaceUid], $current, [
                'customer.workspaces.show', 'customer.workspaces.index', 'customer.workspaces.additional-business-slots.',
            ]);
        }

        if ($context->canManageWorkspace() && (bool) $user->is_customer && $workspaceUid !== null) {
            // This account's own AI Business OS plan (Workspace plan domain) —
            // never the inherited SMS plans/subscriptions page.
            $settings[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.workspaces.plan.show', [$workspaceUid], $current, [
                'customer.workspaces.plan.',
            ]);
        }

        $advanced = $this->advancedItems($context, $user, $current, 'customer.workspaces.businesses.channels.index', $scoped);

        if ($advanced !== null) {
            $settings[] = $advanced;
        }

        $settings = array_values(array_filter($settings));

        if ($settings !== []) {
            $items[] = new MenuItem('settings', 'Settings', null, 'settings', false, $settings);
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

        if (config('opportunity.enabled', false) && $this->hasAnyAccessibleBusiness($context)) {
            // The Advisor queue is a customer-level surface that resolves its
            // own Business; it stays reachable from both frames, but only
            // once the actor has a Business at all — with none there is
            // nothing for the Advisor to recommend on.
            $items[] = $this->item($user, 'advisor', 'Advisor', 'compass', ['access_backend'], 'customer.opportunities.index', [], $current, ['customer.opportunities.']);
        }

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

        $settings = [];
        $planWorkspace = $context->frameWorkspace();

        if ($planWorkspace !== null && $context->canManageWorkspace() && (bool) $user->is_customer) {
            // The Agency (or only) account's own plan — explicit, never a
            // client Business's and never the inherited SMS subscriptions page.
            // With several accounts and none chosen there is no plan to name.
            $settings[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.workspaces.plan.show', [$planWorkspace->uid], $current, [
                'customer.workspaces.plan.',
            ]);
        }

        $advanced = $this->advancedItems($context, $user, $current, 'customer.channels.index', []);

        if ($advanced !== null) {
            $settings[] = $advanced;
        }

        $settings = array_values(array_filter($settings));

        if ($settings !== []) {
            $items[] = new MenuItem('settings', 'Settings', null, 'settings', false, $settings);
        }

        return array_values(array_filter($items));
    }

    /**
     * Contract §8.6 — provider-level surfaces are Agency owner/admin only,
     * each still permission-gated, and never part of the Core/Growth menu.
     *
     * @param  array<int, string|null>  $channelsParameters
     */
    private function advancedItems(CustomerContext $context, User $user, string $current, string $channelsRoute, array $channelsParameters): ?MenuItem
    {
        if (! $this->hasAdvancedProviderAccess($context, $user)) {
            return null;
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

        if ($children === []) {
            return null;
        }

        return new MenuItem('advanced', 'Advanced', null, 'sliders', false, $children);
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
     * Any Business the actor can reach, active or not (a draft Business is
     * still theirs to work on).
     */
    private function hasAnyAccessibleBusiness(CustomerContext $context): bool
    {
        foreach ($context->workspaces as $workspace) {
            if ($workspace->accessibleBusinesses() !== []) {
                return true;
            }
        }

        return false;
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
