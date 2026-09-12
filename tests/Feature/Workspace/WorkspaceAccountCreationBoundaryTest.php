<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * A customer has ONE account (technical Workspace): Core/Growth hold one
 * Business, Agency holds its client Businesses. Customers never create a
 * second Workspace — not from the page, not by a hand-made POST. Someone who
 * owns no account yet can still create their first one; invited memberships
 * of other accounts keep working, with a chooser only when there is a real
 * choice and never a create form beside it.
 */
class WorkspaceAccountCreationBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_a_single_account_owner_goes_straight_to_their_account_not_an_accounts_list(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Own Account');
            $this->authenticateAs($owner);

            $this->get(route('customer.workspaces.index'))->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        }
    }

    public function test_the_account_page_offers_no_way_to_create_another_account(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $page = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        foreach (['Create account', 'New account name', 'Back to accounts'] as $text) {
            $page->assertDontSee($text);
        }
        $page->assertDontSee('action="' . route('customer.workspaces.store') . '"', false);
        $page->assertDontSee('href="' . route('customer.workspaces.index') . '"', false);
    }

    public function test_a_direct_post_cannot_create_a_second_account_for_core_or_growth(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Own Account');
            $this->authenticateAs($owner);
            $before = Workspace::query()->count();

            $this->post(route('customer.workspaces.store'), ['name' => 'Jazmin Media'])
                ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
                ->assertSessionHas('flash_error', 'You already have an account.');

            $this->assertSame($before, Workspace::query()->count(), $tier->value . ' must not gain a Workspace.');
            $this->assertFalse(Workspace::query()->where('name', 'Jazmin Media')->exists());
        }
    }

    public function test_an_inactive_account_still_counts_as_the_customers_account(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $workspace->forceFill(['is_active' => false])->save();
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.store'), ['name' => 'Fresh Start'])
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_error');

        $this->assertSame(1, Workspace::query()->where('owner_user_id', $owner->user_id)->count());
    }

    /**
     * Agency client accounts are Businesses inside the one Agency account:
     * the Workspace POST is refused, the Business flow is untouched.
     */
    public function test_agency_adds_client_accounts_as_businesses_never_as_workspaces(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.store'), ['name' => 'Client Two Account'])
            ->assertSessionHas('flash_error', 'You already have an account.');
        $this->assertSame(1, Workspace::query()->where('owner_user_id', $owner->user_id)->count());

        $this->post(route('customer.workspaces.businesses.store', $workspace->uid), $this->businessAttributes(['name' => 'Client Two']))
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHasNoErrors();

        $this->assertSame($workspace->id, (int) Business::query()->where('name', 'Client Two')->value('workspace_id'));
        $this->assertSame(1, Workspace::query()->where('owner_user_id', $owner->user_id)->count());
    }

    /**
     * An owner who was also invited into another account has a real choice:
     * the chooser lists both — and still offers no creation.
     */
    public function test_an_owner_with_an_invited_membership_gets_a_chooser_without_creation(): void
    {
        [$owner, , $ownWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Own Business', 'Own Account');
        [, , $invitingWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Their Business', 'Inviting Account');
        $this->member($invitingWorkspace, $owner->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($owner);

        $chooser = $this->get(route('customer.workspaces.index'))->assertOk();

        $chooser->assertSee('Choose an account');
        $chooser->assertSee(route('customer.workspaces.show', $ownWorkspace->uid), false);
        $chooser->assertSee(route('customer.workspaces.show', $invitingWorkspace->uid), false);
        $chooser->assertDontSee('Create account');
        $chooser->assertDontSee('action="' . route('customer.workspaces.store') . '"', false);

        $this->get(route('customer.workspaces.show', $ownWorkspace->uid))->assertOk()->assertSee('Back to accounts');
        $this->get(route('customer.workspaces.show', $invitingWorkspace->uid))->assertOk()->assertSee('Inviting Account');
    }

    public function test_an_invited_member_without_an_own_account_still_reaches_the_account_they_joined(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Their Business', 'Joined Account');
        $member = $this->createCustomer();
        $this->member($workspace, $member->user, WorkspaceMembershipRole::Staff);
        $this->authenticateAs($member);

        $this->get(route('customer.workspaces.index'))->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('Joined Account');
    }

    /**
     * The zero-account bootstrap stays: someone who owns no account sees the
     * create form, can create exactly one, and is then refused a second.
     */
    public function test_a_customer_with_no_account_can_create_their_first_one_and_only_one(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $index = $this->get(route('customer.workspaces.index'))->assertOk();
        $index->assertSee('Create your account');
        $index->assertSee('action="' . route('customer.workspaces.store') . '"', false);

        $this->post(route('customer.workspaces.store'), ['name' => 'First Account'])->assertSessionHas('flash_success', 'Account created.');
        $this->post(route('customer.workspaces.store'), ['name' => 'Second Account'])->assertSessionHas('flash_error', 'You already have an account.');

        $this->assertSame(['First Account'], Workspace::query()->where('owner_user_id', $customer->user_id)->pluck('name')->all());
    }

    /**
     * An Agency's client who was invited to their own Business only is not
     * offered to start a separate account from the account list.
     */
    public function test_an_invited_client_is_not_offered_to_create_an_account(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $client = $this->createCustomer();
        $clientBusiness = $this->addBusiness($client, $workspace, 'Client Bakery');
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $clientBusiness);
        $this->authenticateAs($client);

        $index = $this->get(route('customer.workspaces.index'))->assertOk();

        $index->assertDontSee('Create account');
        $index->assertDontSee('action="' . route('customer.workspaces.store') . '"', false);
    }

    /**
     * The boundary is the customer route only; the domain capability that
     * platform/internal provisioning uses is unchanged.
     */
    public function test_platform_provisioning_through_the_domain_capability_is_unchanged(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);

        app(WorkspaceManager::class)->createWorkspace((int) $owner->user_id, 'Provisioned by the platform');

        $this->assertSame(2, Workspace::query()->where('owner_user_id', $owner->user_id)->count());
    }

    public function test_renaming_confirms_with_a_compact_saved_toast_never_workspace_renamed(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Harbor Lane Co'])
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_success', 'Saved');

        $page = $this->withSession(['flash_success' => 'Saved'])->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $page->assertSee('window.toastr.success("Saved"', false);
        $page->assertSee('<div data-role="flash-success-fallback" hidden>', false);
        $page->assertDontSee('Workspace renamed');
        $this->assertSame('Harbor Lane Co', $workspace->fresh()->name);
    }

    public function test_rename_refusals_speak_of_the_account(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $workspace->forceFill(['is_active' => false])->save();
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Anything'])
            ->assertSessionHas('flash_error', 'This account is inactive, so it can\'t be renamed.');
    }

    public function test_settings_account_opens_the_current_account_directly(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $links = $this->menuLinks($this->home()->assertOk()->getContent());

        $this->assertContains(route('customer.workspaces.show', $workspace->uid), $links);
        $this->assertNotContains(route('customer.workspaces.index'), $links);
    }

    public function test_the_account_page_no_longer_offers_moving_a_business_into_another_workspace(): void
    {
        [$owner, , $ownWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Own Business', 'Own Account');
        [, , $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Their Business', 'Other Account');
        $this->member($otherWorkspace, $owner->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($owner);

        $page = $this->get(route('customer.workspaces.show', $ownWorkspace->uid))->assertOk();

        $page->assertDontSee('data-business-action="reassign"', false);
        $page->assertDontSee('Reassign to');
        $page->assertDontSee('name="target_workspace_uid"', false);
    }
}
