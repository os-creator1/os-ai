<?php

namespace Tests\Feature\Navigation;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Navigation\ContextSwitcherPresenter;
use App\Library\Navigation\CustomerContextPreference;
use App\Models\BusinessLocation;
use App\Models\ViewAsSession;
use App\Enums\Business\BusinessServiceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Lane E — the one Account / Business context switcher at the top of the
 * customer sidebar.
 *
 * What is asserted here, in order: the simple Core/Growth state, an invited
 * second account, Agency account <-> client Business in both directions,
 * switching between authorized Businesses, and then the boundaries — an
 * unauthorized Business or account can neither appear nor be selected, the
 * tenant boundary stays fail-closed, no account can be created from here, and
 * a physical Location is never a shell context.
 */
class ContextSwitcherTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // -----------------------------------------------------------------
    // Core / Growth: one account, one Business
    // -----------------------------------------------------------------

    /**
     * A Core or Growth customer has one Business and no account to manage
     * (owner decision): nothing to switch to, and no account link. The block
     * is the labelled identity of the Business they work in, not a menu with
     * one row (navigation redesign §7.1: no switcher for Core or Growth).
     */
    public function test_a_core_owner_with_one_business_gets_a_plain_identity_with_no_clutter(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('data-role="sidebar-context"', $shell);
        $this->assertStringContainsString('data-role="context-identity"', $shell);
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Business\s*</', $shell);
        $this->assertStringContainsString('Harbor Lane Studios', $shell);

        $this->assertStringNotContainsString('id="customer-context-switcher-toggle"', $shell, 'Nothing to switch to.');
        $this->assertSame(0, $this->optionCount($shell, 'context-option-account'));
        $this->assertStringNotContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $shell, 'No account page.');
        $this->assertStringNotContainsString('Account settings', $shell);
        $this->assertStringNotContainsString('data-role="context-switcher-filter"', $shell);
    }

    public function test_the_switcher_never_offers_to_create_an_account(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        foreach (['Create account', 'Create an account', 'New account', 'Add account'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $shell, 'PR #258: a switcher is not a way around the account-creation boundary.');
        }

        // Account settings legitimately links to /workspaces/{uid}; what must
        // never exist is a form posting to the account-creation endpoint.
        $this->assertStringNotContainsString('action="' . route('customer.workspaces.store') . '"', $shell);
    }

    /**
     * Customer shell cleanup — a Core account's own frame would only ask its
     * customer to choose the one Business they have, so it is not a place the
     * shell parks them. Its settings page stays reachable, inside the
     * Business frame.
     */
    public function test_a_core_owner_is_never_parked_in_an_account_frame(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $this->home()->assertOk()->assertSee('Business navigation', false);

        // Even a posted account choice (a stale page, a hand-made request)
        // falls straight through to the Business, on this request and the next.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));

        $home = $this->home()->assertOk();
        $home->assertSee('Business navigation', false);
        $home->assertDontSee('Account navigation', false);
        $this->assertStringNotContainsString('data-kind="chooser"', $home->getContent());
        $this->assertMatchesRegularExpression('/customer-context-current-name[^>]*>\s*Harbor Lane Studios\s*</', $this->shellHtml($home->getContent()));
        $this->home()->assertOk()->assertSee('Business navigation', false);

        // There is no account page to stand in: it opens the Business's Settings.
        $this->get(route('customer.workspaces.show', $workspace->uid))
            ->assertRedirect(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]));
    }

    public function test_a_business_route_ends_a_deliberate_account_choice(): void
    {
        // An Agency account frame is a real destination, so a choice to stand
        // in it holds — until a Business-scoped page says otherwise.
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->createAgencyManagedClient($workspace, 'Client Two', 'Client Two Workspace');
        $this->authenticateAs($agency);

        $this->switchTo($workspace, $clientOne);
        $this->switchToAccount($workspace);
        $this->home()->assertOk()->assertSee('Account navigation', false);

        // Opening the Business's own page is a Business-frame intent.
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]))->assertOk();
        $this->home()->assertOk()->assertSee('Business navigation', false);
    }

    // -----------------------------------------------------------------
    // An invited second account
    // -----------------------------------------------------------------

    public function test_an_invited_second_account_is_offered_and_switchable(): void
    {
        // The customer's own Growth account, and an Agency account they were
        // invited into as an agency-wide admin.
        [$customer, $ownBusiness, $ownWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        [$host, $hostBusiness, $hostWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Bakery', 'Northwind Group');
        $this->member($hostWorkspace, $customer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $this->authenticateAs($customer);
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        // The Businesses of both, each named by its account. Only the Agency
        // account is a frame of its own; the Growth account is entered
        // through its Business.
        $this->assertSame(1, $this->optionCount($shell, 'context-option-account'));
        $this->assertSame(2, $this->optionCount($shell, 'context-option-business'));
        $this->assertStringContainsString('Jazmin Media', $shell);
        $this->assertStringContainsString('Northwind Group', $shell);

        $this->switchTo($ownWorkspace, $ownBusiness)->assertRedirect(route('user.home'));
        $this->assertStringContainsString('Harbor Lane Studios', $this->shellText($this->home()->assertOk()->getContent()));

        // Entering the invited account's frame works and is remembered.
        $this->switchToAccount($hostWorkspace)->assertRedirect(route('user.home'));
        $invited = $this->home()->assertOk();
        $invited->assertSee('Account navigation', false);
        $this->assertStringContainsString('Northwind Group', $this->shellHtml($invited->getContent()));

        // So does entering the invited account's Business.
        $this->switchTo($hostWorkspace, $hostBusiness)->assertRedirect(route('user.home'));
        $business = $this->home()->assertOk();
        $business->assertSee('Business navigation', false);
        $this->assertStringContainsString('Northwind Bakery', $this->shellText($business->getContent()));
    }

    // -----------------------------------------------------------------
    // Agency account <-> client Business
    // -----------------------------------------------------------------

    public function test_an_agency_owner_moves_between_the_agency_home_and_a_client_business(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $managed = $this->createAgencyManagedClient($workspace, 'Client Two', 'Client Two Workspace');
        $this->authenticateAs($agency);

        // The account holds one Business, so that is where the shell opens.
        // The Agency account home is a deliberate move away from it, and the
        // switcher lists only what can be entered: a managed Client lives in
        // its own Workspace the Agency holds no membership in, so it is
        // reached through View As and never offered here.
        $this->home()->assertOk();
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $start = $this->home()->assertOk();
        $startShell = $this->shellHtml($start->getContent());
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Agency account\s*</', $startShell);
        $this->assertSame(1, $this->optionCount($startShell, 'context-option-business'));
        $this->assertStringContainsString('Client accounts', $startShell);
        $this->assertStringNotContainsString($managed['clientBusiness']->name, $startShell);
        $this->assertStringNotContainsString($managed['clientWorkspace']->name, $startShell);

        // Into the Business, and the switcher marks it current.
        $this->switchTo($workspace, $clientOne)->assertRedirect(route('user.home'));
        $inClient = $this->home()->assertOk();
        $clientShell = $this->shellHtml($inClient->getContent());
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Client account\s*</', $clientShell);
        $this->assertSame(1, substr_count($clientShell, 'aria-current="true"'));
        $this->assertStringContainsString('aria-label="Current client account: Client One. Switch client account"', $clientShell);

        // Back to the Agency account home in one action, from inside the client.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $back = $this->home()->assertOk();
        $this->assertContains('prospecting', $this->menuKeys($back->getContent()), 'The Agency account frame is back.');
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Agency account\s*</', $this->shellHtml($back->getContent()));
    }

    /**
     * Two Businesses one actor may enter are two separate accounts they hold
     * membership in — the only shape that exists now that an account holds
     * exactly one Business.
     */
    public function test_switching_between_two_authorized_businesses_changes_the_whole_shell(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $second = $this->reachableSecondAccount($agency, 'Client Two', 'Second Account');
        $this->authenticateAs($agency);

        $this->switchTo($workspace, $clientOne);
        $first = $this->home()->assertOk()->getContent();
        $this->assertContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]), $this->menuLinks($first));

        $this->switchTo($second['workspace'], $second['business']);
        $secondPage = $this->home()->assertOk()->getContent();
        $this->assertContains(route('customer.workspaces.businesses.analytics.overview', [$second['workspace']->uid, $second['business']->uid]), $this->menuLinks($secondPage));
        $this->assertNotContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]), $this->menuLinks($secondPage));
    }

    public function test_a_filter_appears_only_once_there_are_enough_businesses_to_need_one(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client 01', 'Northwind Agency');

        for ($i = 2; $i <= ContextSwitcherPresenter::FILTER_THRESHOLD - 1; $i++) {
            $label = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->reachableSecondAccount($agency, 'Client ' . $label, 'Account ' . $label);
        }

        $this->authenticateAs($agency);
        $below = $this->shellHtml($this->home()->assertOk()->getContent());
        $this->assertSame(ContextSwitcherPresenter::FILTER_THRESHOLD - 1, $this->optionCount($below, 'context-option-business'));
        $this->assertStringNotContainsString('data-role="context-switcher-filter"', $below);

        $this->reachableSecondAccount($agency, 'Client ' . ContextSwitcherPresenter::FILTER_THRESHOLD, 'Account ' . ContextSwitcherPresenter::FILTER_THRESHOLD);
        $atThreshold = $this->shellHtml($this->home()->assertOk()->getContent());
        $this->assertStringContainsString('data-role="context-switcher-filter"', $atThreshold);
        $this->assertStringContainsString('<label class="visually-hidden" for="customer-context-switcher-filter">', $atThreshold);
    }

    // -----------------------------------------------------------------
    // Boundaries
    // -----------------------------------------------------------------

    public function test_a_selected_scope_member_sees_neither_the_account_nor_its_name(): void
    {
        [$agencyOwner, $houseBusiness, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency House Business', 'Northwind Agency');
        $managed = $this->createAgencyManagedClient($workspace, 'Client Bakery', 'Client Bakery Workspace');
        $client = $this->createCustomer();
        $membership = $this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $houseBusiness);
        $this->authenticateAs($client);

        $html = $this->home()->assertOk()->getContent();
        $shell = $this->shellHtml($html);

        // One Business, no account frame to reach: a plain identity, not a menu.
        $this->assertStringContainsString('data-role="context-identity"', $shell);
        $this->assertStringNotContainsString('customer-context-switcher-toggle', $shell);
        $this->assertStringNotContainsString('context-option-account', $shell);
        $this->assertStringNotContainsString('Northwind Agency', $html, 'S-6: the agency account name is never disclosed to a client.');

        // Their own assigned Business is all they are shown; the account's
        // managed client — its own account entirely — stays invisible.
        $this->assertStringContainsString('Agency House Business', $shell);
        $this->assertStringNotContainsString($managed['clientBusiness']->name, $html);
        $this->assertStringNotContainsString($managed['clientWorkspace']->name, $html);

        // And the action itself refuses, not merely the menu.
        $this->switchToAccount($workspace)->assertNotFound();
    }

    public function test_an_unauthorized_account_can_neither_appear_nor_be_selected(): void
    {
        [$mine, , $myWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        [$stranger, $strangerBusiness, $strangerWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Business', 'Stranger Group');

        $this->authenticateAs($mine);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('Stranger Group', $html);
        $this->assertStringNotContainsString('Stranger Business', $html);

        $this->switchToAccount($strangerWorkspace)->assertNotFound();
        $this->switchTo($strangerWorkspace, $strangerBusiness)->assertNotFound();

        // The refusal changed nothing: the actor is still in their own context,
        // and no foreign account was remembered for them.
        $after = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('Harbor Lane Studios', $this->shellText($after));
        $this->assertNotSame($strangerWorkspace->uid, app(CustomerContextPreference::class)->get()['workspace']);
        $this->assertStringNotContainsString('Stranger', $after);
    }

    public function test_an_inactive_account_is_not_a_destination(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        $workspace->forceFill(['is_active' => false])->save();

        $this->authenticateAs($customer);
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringNotContainsString('context-option-account', $shell);
        $this->switchToAccount($workspace)->assertNotFound();
    }

    public function test_an_inactive_business_is_never_a_switcher_entry(): void
    {
        [$agency, $active, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Active Client', 'Northwind Agency');
        $paused = $this->reachableSecondAccount($agency, 'Paused Client', 'Paused Account', BusinessStatus::Inactive);
        $this->authenticateAs($agency);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('Active Client', $shell);
        $this->assertStringNotContainsString('Paused Client', $shell);
        $this->switchTo($paused['workspace'], $paused['business'])->assertNotFound();
    }

    public function test_the_switcher_never_lists_a_physical_location(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');

        foreach (['Downtown Storefront Location', 'Riverside Branch Location'] as $index => $name) {
            BusinessLocation::create([
                'business_id' => $business->id,
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

        foreach (['Downtown Storefront Location', 'Riverside Branch Location'] as $name) {
            $this->assertStringNotContainsString($name, $html, 'A physical Location is managed inside a Business, never a shell context.');
        }
    }

    public function test_the_account_switch_is_a_validated_post_only_action(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        // No GET is registered for it; this application answers an
        // unregistered verb with 404, as WorkspaceSwitcherHttpTest records.
        $this->get(route('customer.context.account.switch'))->assertNotFound();
        $this->post(route('customer.context.account.switch'), [])->assertSessionHasErrors('workspace');
    }

    public function test_a_guest_cannot_switch_context(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        // Handler::render() answers AuthenticationException with 401 for every
        // customer.php route under phpunit, never a login redirect (the same
        // boundary proof WorkspaceSwitcherHttpTest carries).
        $this->post(route('customer.context.account.switch'), ['workspace' => $workspace->uid])
            ->assertUnauthorized();
    }

    public function test_viewing_as_a_client_replaces_the_switcher_with_the_viewed_identity(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $clientOne, 'Checking a reported problem.');
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('data-role="context-identity"', $shell);
        $this->assertStringNotContainsString('customer-context-switcher-toggle', $shell);
        $this->assertStringNotContainsString('context-option-account', $shell);

        // Switching context from inside a viewing session is prohibited, and
        // refused the same audited way every other prohibited action is.
        $refused = $this->switchToAccount($workspace);
        $refused->assertRedirect(route('user.home'));
        $refused->assertSessionHas('status', 'warning');
        $refused->assertSessionHas('message', fn (string $message) => str_contains($message, 'not available while you are viewing this client account'));
        $this->assertContains(
            'customer.context.account.switch',
            array_column(ViewAsSession::query()->whereNull('ended_at')->sole()->refusals ?? [], 'route'),
        );
    }

    public function test_the_block_renders_once_and_only_in_the_sidebar(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->createAgencyManagedClient($workspace, 'Client Two', 'Client Two Workspace');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="customer-context-switcher-toggle"'), 'One control per page, never two.');
        $this->assertSame(1, substr_count($html, 'data-variant="sidebar"'));
        $this->assertStringNotContainsString('data-variant="navbar"', $html);
    }

    /**
     * The options of one kind inside the rendered shell.
     */
    private function optionCount(string $shell, string $role): int
    {
        return substr_count($shell, 'data-role="' . $role . '"');
    }

    /**
     * A second account $actor can genuinely enter: its own Workspace holding
     * its own single Business, with $actor an active agency-wide member of it.
     * An account holds exactly one Business, so this — not a sibling Business
     * — is how one actor reaches more than one.
     *
     * @return array{customer: \App\Models\Customer, business: \App\Models\Business, workspace: \App\Models\Workspace}
     */
    private function reachableSecondAccount(
        \App\Models\Customer $actor,
        string $businessName,
        string $workspaceName,
        BusinessStatus $status = BusinessStatus::Active,
    ): array {
        $account = $this->createIndependentWorkspaceBusiness(
            businessName: $businessName,
            workspaceName: $workspaceName,
            status: $status,
        );

        $this->member($account['workspace'], $actor->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        return $account;
    }
}
