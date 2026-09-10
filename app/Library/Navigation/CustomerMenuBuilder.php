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
    public function build(CustomerContext $context, User $user): array
    {
        $current = (string) Route::currentRouteName();
        $this->viewingAsClient = $context->isViewingAsClient();

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

        $items[] = $this->item($user, 'contacts', 'Contacts', 'users', self::CONTACT_PERMISSIONS, 'customer.workspaces.businesses.contacts.index', $scoped, $current, [
            'customer.workspaces.businesses.contacts.', 'customer.workspaces.businesses.contact.', 'customer.contacts.', 'customer.contact.',
        ]);
        $items[] = $this->item($user, 'conversations', 'Conversations', 'message-square', ['chat_box'], 'customer.chatbox.index', [], $current, ['customer.chatbox.']);
        $items[] = $this->item($user, 'campaigns', 'Campaigns', 'send', self::OUTREACH_PERMISSIONS, 'customer.workspaces.businesses.outreach.campaigns', $scoped, $current, [
            'customer.workspaces.businesses.outreach.', 'customer.outreach.', 'customer.sms.', 'customer.mms.',
        ]);
        $items[] = $this->item($user, 'automations', 'Automations', 'cpu', ['automations'], 'customer.workspaces.businesses.automations.index', $scoped, $current, [
            'customer.workspaces.businesses.automations.', 'customer.automations.',
        ]);
        $items[] = $this->item($user, 'website', 'Website', 'globe', ['website'], 'customer.workspaces.businesses.website.show', $scoped, $current, [
            'customer.workspaces.businesses.website.', 'customer.website.',
        ]);
        $items[] = $this->item($user, 'gbp', 'Google Business Profile', 'map-pin', ['view_google_business_profile'], 'customer.workspaces.businesses.gbp.index', $scoped, $current, [
            'customer.workspaces.businesses.gbp.', 'customer.gbp.',
        ]);
        $items[] = $this->item($user, 'analytics', 'Analytics', 'bar-chart-2', ['view_reports'], 'customer.workspaces.businesses.analytics.overview', $scoped, $current, [
            'customer.workspaces.businesses.analytics.', 'customer.analytics.',
        ]);

        $settings = [];

        if ($context->selectedBusiness !== null
            && $context->selectedBusiness->customerId === $context->userId
            && $context->selectedBusiness->isPrimary) {
            // BusinessController@edit resolves the customer's PRIMARY
            // Business; it is only offered when that is the selected one.
            $settings[] = $this->item($user, 'business-details', 'Business details', 'briefcase', ['access_backend'], 'customer.business.edit', [], $current, ['customer.business.']);
        }

        $settings[] = $this->item($user, 'blocked-numbers', 'Blocked numbers', 'shield', self::BLACKLIST_PERMISSIONS, 'customer.blacklists.index', [], $current, ['customer.blacklists.']);

        if ($context->canManageBilling()) {
            $settings[] = $this->item($user, 'usage-billing', 'Usage & billing', 'credit-card', ['access_backend'], 'customer.workspaces.businesses.usage-billing.show', $scoped, $current, [
                'customer.workspaces.businesses.usage-billing.',
            ]);
        }

        if ($context->canManageWorkspace() && $workspaceUid !== null) {
            $settings[] = $this->item($user, 'team', $context->isAgency() ? 'Team & agency account' : 'Team & account', 'user-check', ['access_backend'], 'customer.workspaces.show', [$workspaceUid], $current, [
                'customer.workspaces.show', 'customer.workspaces.index', 'customer.workspaces.additional-business-slots.',
            ]);
        }

        if ($context->canManageWorkspace() && (bool) $user->is_customer) {
            $settings[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.subscriptions.index', [], $current, [
                'customer.subscriptions.', 'customer.invoices.',
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

        if ($context->canManageWorkspace() && (bool) $user->is_customer) {
            $settings[] = $this->item($user, 'plan', 'Plan & subscription', 'tag', ['access_backend'], 'customer.subscriptions.index', [], $current, [
                'customer.subscriptions.', 'customer.invoices.',
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
        if (! $context->isAgency() || ! $context->canManageWorkspace()) {
            return null;
        }

        $children = array_values(array_filter([
            $this->item($user, 'messaging-provider', 'Messaging provider', 'link', ['view_numbers'], $channelsRoute, $channelsParameters, $current, [
                'customer.workspaces.businesses.channels.', 'customer.channels.',
            ]),
            $this->item($user, 'sender-ids', 'Sender identities', 'book', ['view_sender_id'], 'customer.senderid.index', [], $current, ['customer.senderid.']),
            $this->item($user, 'numbers', 'Numbers', 'phone', ['view_numbers'], 'customer.numbers.index', [], $current, ['customer.numbers.']),
            $this->item($user, 'keywords', 'Keywords', 'hash', ['view_keywords'], 'customer.keywords.index', [], $current, ['customer.keywords.']),
            $this->item($user, 'developers', 'Developers', 'terminal', ['developers'], 'customer.developer.settings', [], $current, ['customer.developer.']),
        ]));

        if ($children === []) {
            return null;
        }

        return new MenuItem('advanced', 'Advanced', null, 'sliders', false, $children);
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
