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

    public function test_a_core_owner_with_one_business_gets_a_clickable_block_with_no_clutter(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        // The whole block is one control: frame label, current name, chevron.
        $this->assertStringContainsString('data-role="sidebar-context"', $shell);
        $this->assertStringContainsString('id="customer-context-switcher-toggle"', $shell);
        $this->assertStringContainsString('aria-label="Current business: Harbor Lane Studios. Switch business"', $shell);
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Business\s*</', $shell);
        $this->assertStringContainsString('Harbor Lane Studios', $shell);

        // Their own Business is listed as current, and their account is the
        // one other destination. Nothing else.
        $this->assertSame(1, $this->optionCount($shell, 'context-option-business'));
        $this->assertSame(1, $this->optionCount($shell, 'context-option-account'));
        $this->assertStringContainsString('Jazmin Media', $shell);
        $this->assertSame(1, substr_count($shell, 'aria-current="true"'));

        // No search box over one Business, and no invented multi-account UI.
        // (The block still ships the small focus-return script every actor
        // needs for Escape; what a one-Business customer must not get is the
        // filter.)
        $this->assertStringNotContainsString('data-role="context-switcher-filter"', $shell);
        $this->assertStringNotContainsString('Search business', $shell);
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

    public function test_a_core_owner_can_enter_the_account_frame_and_come_back(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $this->home()->assertOk()->assertSee('Business navigation', false);

        // Account: the shell becomes the account frame and STAYS there, even
        // though exactly one Business could be resolved.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));

        $account = $this->home()->assertOk();
        $account->assertSee('Account navigation', false);
        $accountShell = $this->shellHtml($account->getContent());
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Account\s*</', $accountShell);
        $this->assertStringContainsString('Jazmin Media', $accountShell);

        // The block names the account it is standing in — never "No business
        // yet" to someone who has one. That fallback was unreachable before
        // this switcher could enter the account frame.
        $this->assertMatchesRegularExpression(
            '/customer-context-current-name[^>]*>\s*Jazmin Media\s*</',
            $accountShell,
            'The account frame names the account.'
        );
        $this->assertStringNotContainsString('No business yet', $accountShell);
        $this->assertStringNotContainsString('No client account yet', $accountShell);

        // The document title names the same context the block does.
        $this->assertStringContainsString('<title>Dashboard · Jazmin Media', $account->getContent());
        $this->assertContains('accounts', $this->menuKeys($account->getContent()));

        // A second request keeps the choice (the preference, not a one-off).
        $this->home()->assertOk()->assertSee('Account navigation', false);

        // And the Business is one click away again.
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));
        $this->home()->assertOk()->assertSee('Business navigation', false);
    }

    public function test_a_business_route_ends_a_deliberate_account_choice(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->switchToAccount($workspace);
        $this->home()->assertOk()->assertSee('Account navigation', false);

        // Opening the Business's own page is a Business-frame intent.
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
        $this->home()->assertOk()->assertSee('Business navigation', false);
    }

    // -----------------------------------------------------------------
    // An invited second account
    // -----------------------------------------------------------------

    public function test_an_invited_second_account_is_offered_and_switchable(): void
    {
        [$customer, $ownBusiness, $ownWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        [$host, $hostBusiness, $hostWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Northwind Bakery', 'Northwind Group');
        $this->member($hostWorkspace, $customer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $this->authenticateAs($customer);
        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        // Both accounts, and the Businesses of both, each named by its account.
        $this->assertSame(2, $this->optionCount($shell, 'context-option-account'));
        $this->assertSame(2, $this->optionCount($shell, 'context-option-business'));
        $this->assertStringContainsString('Jazmin Media', $shell);
        $this->assertStringContainsString('Northwind Group', $shell);

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
        $clientTwo = $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        // Account frame first (two clients, none chosen).
        $start = $this->home()->assertOk();
        $startShell = $this->shellHtml($start->getContent());
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Agency account\s*</', $startShell);
        $this->assertSame(2, $this->optionCount($startShell, 'context-option-business'));
        $this->assertStringContainsString('Client accounts', $startShell);

        // Into a client, and the switcher marks it current.
        $this->switchTo($workspace, $clientTwo)->assertRedirect(route('user.home'));
        $inClient = $this->home()->assertOk();
        $clientShell = $this->shellHtml($inClient->getContent());
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Client account\s*</', $clientShell);
        $this->assertSame(1, substr_count($clientShell, 'aria-current="true"'));
        $this->assertStringContainsString('aria-label="Current client account: Client Two. Switch client account"', $clientShell);

        // Back to the Agency account home in one action, from inside the client.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $back = $this->home()->assertOk();
        $this->assertContains('prospecting', $this->menuKeys($back->getContent()), 'The Agency account frame is back.');
        $this->assertMatchesRegularExpression('/customer-context-frame[^>]*>\s*Agency account\s*</', $this->shellHtml($back->getContent()));
    }

    public function test_switching_between_two_authorized_businesses_changes_the_whole_shell(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $clientTwo = $this->addBusiness($agency, $workspace, 'Client Two');
        $this->authenticateAs($agency);

        $this->switchTo($workspace, $clientOne);
        $first = $this->home()->assertOk()->getContent();
        $this->assertContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]), $this->menuLinks($first));

        $this->switchTo($workspace, $clientTwo);
        $second = $this->home()->assertOk()->getContent();
        $this->assertContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientTwo->uid]), $this->menuLinks($second));
        $this->assertNotContains(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]), $this->menuLinks($second));
    }

    public function test_a_filter_appears_only_once_there_are_enough_businesses_to_need_one(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client 01', 'Northwind Agency');

        for ($i = 2; $i <= ContextSwitcherPresenter::FILTER_THRESHOLD - 1; $i++) {
            $this->addBusiness($agency, $workspace, 'Client ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->authenticateAs($agency);
        $below = $this->shellHtml($this->home()->assertOk()->getContent());
        $this->assertSame(ContextSwitcherPresenter::FILTER_THRESHOLD - 1, $this->optionCount($below, 'context-option-business'));
        $this->assertStringNotContainsString('data-role="context-switcher-filter"', $below);

        $this->addBusiness($agency, $workspace, 'Client ' . ContextSwitcherPresenter::FILTER_THRESHOLD);
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
        $client = $this->createCustomer();
        $clientBusiness = $this->addBusiness($client, $workspace, 'Client Bakery');
        $membership = $this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $clientBusiness);
        $this->authenticateAs($client);

        $html = $this->home()->assertOk()->getContent();
        $shell = $this->shellHtml($html);

        // One Business, no account frame to reach: a plain identity, not a menu.
        $this->assertStringContainsString('data-role="context-identity"', $shell);
        $this->assertStringNotContainsString('customer-context-switcher-toggle', $shell);
        $this->assertStringNotContainsString('context-option-account', $shell);
        $this->assertStringNotContainsString('Northwind Agency', $html, 'S-6: the agency account name is never disclosed to a client.');
        $this->assertStringNotContainsString('Agency House Business', $html);

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
        $inactive = $this->addBusiness($agency, $workspace, 'Paused Client', BusinessStatus::Inactive);
        $this->authenticateAs($agency);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('Active Client', $shell);
        $this->assertStringNotContainsString('Paused Client', $shell);
        $this->switchTo($workspace, $inactive)->assertNotFound();
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
        $this->addBusiness($agency, $workspace, 'Client Two');
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
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
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
}
