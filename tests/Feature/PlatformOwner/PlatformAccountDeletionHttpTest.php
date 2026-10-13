<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Models\PlatformAccountClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The Platform Owner > Users account page and the deletion confirmation flow
 * over HTTP: the unified account overview, the separate Danger Zone, honest
 * (non-green) messaging, and server-side enforcement of every rule.
 */
class PlatformAccountDeletionHttpTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        $this->admin = $this->actingAsPlatformOwner(array_merge($this->ownerPermissions, ['view customer', 'delete customer']));
    }

    private function fingerprint(User $user): string
    {
        return app(PlatformAccountAssessor::class)->assess($user)['fingerprint'];
    }

    public function test_the_account_page_is_one_place_for_workspaces_businesses_billing_and_the_danger_zone(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Harbor Lane Studios');
        $user = $fixture['customer']->user;

        $response = $this->get(route('admin.platform-users.show', $user->uid))->assertOk();

        $response->assertSee('Owned Workspaces')
            ->assertSee('Harbor Lane Studios')
            ->assertSee('Subscription: active', false)
            ->assertSee('Staff memberships and Agency relationships')
            ->assertSee('Danger zone')
            ->assertSee('Sign out of all sessions')
            ->assertSee('Suspend account')
            ->assertSee('Close customer account');
        // The danger action is its own card, away from the support actions.
        $html = $response->getContent();
        $this->assertGreaterThan(strpos($html, 'id="user-support-actions"'), strpos($html, 'id="user-danger-zone"'));
    }

    public function test_a_clean_account_is_offered_complete_deletion_and_the_preflight_names_the_impact(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Clean Studio', 'Clean Workspace');

        $this->get(route('admin.platform-users.show', $customer->user->uid))->assertSee('Delete customer account');

        $this->get(route('admin.platform-users.delete', $customer->user->uid))
            ->assertOk()
            ->assertSee('Delete account')
            ->assertSee('Complete deletion is possible')
            ->assertSee($customer->user->email)
            ->assertSee('Clean Workspace')
            ->assertSee('Clean Studio');
    }

    public function test_an_account_with_billing_history_is_labelled_close_not_delete(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');

        $this->get(route('admin.platform-users.delete', $fixture['customer']->user->uid))
            ->assertOk()
            ->assertSee('Close account')
            ->assertSee('Complete deletion is not possible; the account will be closed.')
            ->assertSee('cancelled at the payment provider now', false);
    }

    public function test_confirming_with_the_wrong_email_changes_nothing_and_shows_an_error(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $user = $customer->user;

        $this->from(route('admin.platform-users.delete', $user->uid))
            ->post(route('admin.platform-users.destroy', $user->uid), [
                'confirm_email' => 'someone-else@example.test',
                'reason' => 'Customer request',
                'fingerprint' => $this->fingerprint($user),
            ])
            ->assertRedirect(route('admin.platform-users.delete', $user->uid))
            ->assertSessionHas('status', 'error');

        $this->assertTrue(User::where('id', $user->id)->exists());
        $this->assertSame(0, PlatformAccountClosure::count());
    }

    public function test_a_clean_account_is_deleted_and_the_message_is_a_success(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $user = $customer->user;

        $this->post(route('admin.platform-users.destroy', $user->uid), [
            'confirm_email' => strtoupper($user->email),
            'reason' => 'Customer request',
            'fingerprint' => $this->fingerprint($user),
        ])->assertRedirect(route('admin.platform-users.index'))->assertSessionHas('status', 'success');

        $this->assertFalse(User::where('id', $user->id)->exists());

        // A repeat (double-click, stale tab) is harmless and says it was already deleted.
        $this->post(route('admin.platform-users.destroy', $user->uid), [
            'confirm_email' => $user->email,
            'reason' => 'Customer request',
            'fingerprint' => str_repeat('a', 64),
        ])->assertRedirect(route('admin.platform-users.index'));
        $this->assertSame(1, PlatformAccountClosure::count());

        $this->get(route('admin.platform-users.show', $user->uid))->assertRedirect(route('admin.platform-users.index'));
    }

    public function test_a_failed_cancellation_is_shown_as_pending_cancellation_never_as_green_success(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $user = $fixture['customer']->user;
        $this->stripe->failWith = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);

        $this->post(route('admin.platform-users.destroy', $user->uid), [
            'confirm_email' => $user->email,
            'reason' => 'Customer request',
            'fingerprint' => $this->fingerprint($user),
        ])->assertRedirect(route('admin.platform-users.show', $user->uid))
            ->assertSessionHas('status', 'warning');

        $page = $this->get(route('admin.platform-users.show', $user->uid))->assertOk();
        $page->assertSee('Pending cancellation')
            ->assertSee('not finished')
            ->assertSee('Retry now')
            ->assertDontSee('alert-success', false);

        $this->stripe->failWith = null;
        $this->post(route('admin.platform-users.closure.retry', $user->uid))->assertRedirect()->assertSessionHas('status', 'warning');

        $this->get(route('admin.platform-users.show', $user->uid))->assertSee('Closed — records retained');
        $this->get(route('admin.platform-users.index'))->assertSee('Closed — records retained');
    }

    public function test_a_closed_account_cannot_be_reactivated_from_the_actions_endpoint(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $user = $fixture['customer']->user;

        $this->post(route('admin.platform-users.destroy', $user->uid), [
            'confirm_email' => $user->email, 'reason' => 'Customer request', 'fingerprint' => $this->fingerprint($user),
        ])->assertSessionHas('status', 'warning');

        $this->post(route('admin.platform-users.act', [$user->uid, 'reactivate']), ['reason' => 'Undo it'])
            ->assertSessionHas('status', 'error');
        $this->assertFalse((bool) User::find($user->id)->status);
    }

    public function test_without_the_delete_permission_nothing_can_be_deleted(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner($this->ownerPermissions);

        $this->get(route('admin.platform-users.delete', $customer->user->uid))->assertUnauthorized();
        $this->post(route('admin.platform-users.destroy', $customer->user->uid), [
            'confirm_email' => $customer->user->email, 'reason' => 'x y z', 'fingerprint' => str_repeat('a', 64),
        ])->assertUnauthorized();

        $this->assertTrue(User::where('id', $customer->user->id)->exists());
    }

    public function test_a_customer_workspace_owner_cannot_reach_the_deletion_endpoints(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        [$victim] = $this->tenant(WorkspacePlanTier::Core, 'Victim Studio', 'Victim');
        $this->actingAs($customer->user);

        $this->post(route('admin.platform-users.destroy', $victim->user->uid), [
            'confirm_email' => $victim->user->email, 'reason' => 'x y z', 'fingerprint' => str_repeat('a', 64),
        ])->assertUnauthorized();
        $this->assertTrue(User::where('id', $victim->user->id)->exists());
    }

    public function test_the_legacy_customer_delete_refuses_workspace_owners(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);

        $this->actingAsPlatformOwner(array_merge($this->ownerPermissions, ['delete customer', 'view customer']));

        $this->deleteJson(route('admin.customers.destroy', $customer->user->uid))
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertTrue(User::where('id', $customer->user->id)->exists());
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $customer->user->id)->count());
    }
}
