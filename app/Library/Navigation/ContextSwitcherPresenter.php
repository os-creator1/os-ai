<?php

namespace App\Library\Navigation;

use Illuminate\Support\Facades\Route;

/**
 * The ONE context switcher: the clickable block at the top of the customer
 * sidebar that moves the shell between the account frame and a Business.
 *
 * It invents no hierarchy. The canonical one is account -> Business ->
 * physical Locations, and only the first two levels are app-shell contexts:
 * a BusinessLocation is managed inside a Business (Settings -> Business ->
 * Locations) and is deliberately NOT offered here.
 *
 * It also invents no authorization. Everything it lists comes from the
 * already-resolved CustomerContext, so the switcher can never offer what the
 * resolver would refuse:
 *
 *  - Businesses are CustomerContext::selectableBusinesses() - reachable AND
 *    active, across the accounts the actor can see;
 *  - an account is offered only when the actor has a genuine account-frame
 *    path to it (WorkspaceCandidate::seesAccountFrame(), the same
 *    owner-or-active-scope-all rule the account page enforces before it
 *    answers 404), which is also why a client who is only a selected-scope
 *    member of an agency account never learns that account's name (S-6);
 *  - every choice posts to a server-authorized action that re-resolves and
 *    re-authorizes the submitted uids (S-3).
 *
 * There is no "create account" control: PR #258 settled that a customer never
 * creates a second account, and a switcher is not a way around it.
 *
 * Pure: no query, no session write, no container lookup. It is built per
 * composed shell view from data the request already resolved.
 */
final class ContextSwitcherPresenter
{
    /**
     * Above this many Businesses a filter earns its place. Below it, a search
     * box over a handful of rows is clutter - a Core or Growth customer with
     * one Business must never see one.
     */
    public const FILTER_THRESHOLD = 8;

    public function present(CustomerContext $context): ContextSwitcherView
    {
        $businesses = $this->businesses($context);
        $accounts = $this->accounts($context);
        $links = $this->links($context);

        return new ContextSwitcherView(
            interactive: $this->isInteractive($context, $businesses, $accounts, $links),
            frameLabel: $this->frameLabel($context),
            currentName: $context->contextName(),
            toggleAriaLabel: $this->toggleAriaLabel($context),
            identityAriaLabel: $this->identityAriaLabel($context),
            businessesHeading: $context->businessesNoun(),
            accountsHeading: ucfirst($context->accountsNoun()),
            businesses: $businesses,
            accounts: $accounts,
            links: $links,
            showsFilter: count($businesses) >= self::FILTER_THRESHOLD,
            filterLabel: 'Search ' . strtolower($context->businessesNoun()),
        );
    }

    /**
     * @param  array<int, ContextSwitcherOption>  $businesses
     * @param  array<int, ContextSwitcherOption>  $accounts
     * @param  array<int, ContextSwitcherLink>  $links
     */
    private function isInteractive(CustomerContext $context, array $businesses, array $accounts, array $links): bool
    {
        // While viewing as a client the shell shows the viewed client's
        // identity and nothing else; the banner carries the Exit control
        // (contract §5.5). Switching context from inside a viewing session is
        // a prohibited action, not a disabled one.
        if ($context->isViewingAsClient()) {
            return false;
        }

        if ($links !== []) {
            return true;
        }

        foreach (array_merge($businesses, $accounts) as $option) {
            if ($option->isActionable() || $option->viewAsUrl !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, ContextSwitcherOption>
     */
    private function businesses(CustomerContext $context): array
    {
        if (! Route::has('customer.context.business.switch')) {
            return [];
        }

        $switchUrl = route('customer.context.business.switch');
        $viewAsUrl = $context->canViewAsClient() && Route::has('customer.view-as.start')
            ? route('customer.view-as.start')
            : null;
        $showsAccountName = $context->hasMultipleWorkspaces();
        $options = [];

        foreach ($context->selectableBusinesses() as $business) {
            $options[] = new ContextSwitcherOption(
                name: $business->name,
                subtitle: $showsAccountName ? $business->workspaceName : null,
                switchUrl: $switchUrl,
                workspaceUid: $business->workspaceUid,
                businessUid: $business->uid,
                isCurrent: $context->selectedBusiness !== null && $context->selectedBusiness->uid === $business->uid,
                viewAsUrl: $viewAsUrl,
            );
        }

        return $options;
    }

    /**
     * The accounts whose own frame the actor may enter. An inactive account is
     * left out: SwitchAccountAction refuses it exactly as SwitchBusinessAction
     * already refuses an inactive account's Business, so listing it would
     * offer a door that does not open.
     *
     * @return array<int, ContextSwitcherOption>
     */
    private function accounts(CustomerContext $context): array
    {
        if (! Route::has('customer.context.account.switch')) {
            return [];
        }

        $switchUrl = route('customer.context.account.switch');
        $currentUid = $context->isBusinessFrame() ? null : $context->frameWorkspace()?->uid;
        $options = [];

        foreach ($context->workspaces as $workspace) {
            if (! $workspace->isActive || ! $workspace->seesAccountFrame()) {
                continue;
            }

            $options[] = new ContextSwitcherOption(
                name: $workspace->name,
                subtitle: null,
                switchUrl: $switchUrl,
                workspaceUid: $workspace->uid,
                businessUid: null,
                isCurrent: $currentUid !== null && $currentUid === $workspace->uid,
            );
        }

        return $options;
    }

    /**
     * The account's own page, named the way the rest of the shell names it: an
     * Agency reads its client list there ("All client accounts", the Slice 1B
     * label), a Core or Growth customer reads their account settings. Same
     * page, same authorization - only the word the customer already knows
     * differs.
     *
     * @return array<int, ContextSwitcherLink>
     */
    private function links(CustomerContext $context): array
    {
        $workspace = $context->frameWorkspace();

        if ($workspace === null || ! $workspace->isActive || ! $workspace->seesAccountFrame()
            || ! Route::has('customer.workspaces.show')) {
            return [];
        }

        $label = $context->isAgency()
            ? 'All ' . strtolower($context->businessesNoun())
            : 'Account settings';

        return [new ContextSwitcherLink($label, route('customer.workspaces.show', $workspace->uid))];
    }

    private function frameLabel(CustomerContext $context): string
    {
        return $context->isBusinessFrame()
            ? $context->businessNoun()
            : ucfirst($context->accountNoun());
    }

    /**
     * Unchanged from Slice 1B: the control's primary job is still choosing or
     * switching the Business, and its accessible name says so in the
     * customer's own vocabulary. The account destinations inside the menu
     * carry their own labels under a named group.
     */
    private function toggleAriaLabel(CustomerContext $context): string
    {
        $noun = strtolower($context->businessNoun());

        return $context->selectedBusiness !== null
            ? 'Current ' . $noun . ': ' . $context->selectedBusiness->name . '. Switch ' . $noun
            : 'Choose a ' . $noun;
    }

    private function identityAriaLabel(CustomerContext $context): string
    {
        if ($context->isViewingAsClient()) {
            return 'Current client account';
        }

        return 'Current ' . strtolower($this->frameLabel($context));
    }
}
