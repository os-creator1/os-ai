<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Customer;
use App\Models\ViewAsSession;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — Correction Round 1, Correction 3.
 *
 * A stored view-as session is re-validated against the authoritative
 * access chain on every request. When any link is lost the durable row is
 * ended as access_lost, the browser session key is cleared, no view-as
 * context is returned, the actor's normal context is restored, and no
 * zombie session keeps applying prohibited-action restrictions.
 */
class ViewAsAccessLossTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_a_deactivated_membership_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $admin = $this->createCustomer();
        $membership = $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $membership->is_active = false;
        $membership->save();

        $this->assertViewEndedAsAccessLost($admin);
    }

    public function test_a_role_reduced_below_view_as_authority_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $admin = $this->createCustomer();
        $membership = $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $membership->role = WorkspaceMembershipRole::Staff;
        $membership->save();

        $this->assertViewEndedAsAccessLost($admin);
    }

    public function test_revoked_business_access_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $admin = $this->createCustomer();
        $membership = $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $client);
        $this->authenticateAs($admin);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        WorkspaceMembershipBusiness::query()->where('workspace_membership_id', $membership->id)->delete();

        $this->assertViewEndedAsAccessLost($admin);
    }

    public function test_a_deactivated_workspace_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);

        $this->assertViewEndedAsAccessLost($agency);
    }

    public function test_a_business_made_non_active_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        DB::table('businesses')->where('id', $client->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->assertViewEndedAsAccessLost($agency);
    }

    public function test_a_business_moved_out_of_the_workspace_ends_the_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        [, , $otherWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Elsewhere Client', 'Other Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        DB::table('businesses')->where('id', $client->id)->update(['workspace_id' => $otherWorkspace->id]);

        $this->assertViewEndedAsAccessLost($agency);
    }

    /**
     * The end is atomic and complete: the row is ended with the exact
     * reason, the session key is gone, the banner is gone, the actor is back
     * in their own frame, and a previously prohibited action is no longer
     * refused-as-viewing (it is judged on the actor's own rights instead).
     */
    private function assertViewEndedAsAccessLost(Customer $actor): void
    {
        $home = $this->home()->assertOk();

        $row = ViewAsSession::query()->sole();
        $this->assertNotNull($row->ended_at, 'The durable session must be ended.');
        $this->assertSame(ViewAsSession::END_REASON_ACCESS_LOST, $row->end_reason);
        $this->assertNull(session(ViewAsManager::SESSION_KEY), 'The browser session key must be cleared.');
        $home->assertDontSee('data-role="view-as-banner"', false);
        $this->assertSame((int) $actor->user_id, auth()->id());

        // No zombie restriction: the switch route answers on the actor's own
        // rights (validation/404), never with the "viewing this client" refusal.
        $response = $this->post(route('customer.context.business.switch'), ['workspace' => 'none', 'business' => 'none']);
        $this->assertNotSame(route('user.home'), $response->headers->get('Location'));
        $response->assertSessionMissing('message');
        $this->assertSame([], $row->fresh()->refusals ?? [], 'Nothing is audited against an ended session.');
        $this->assertSame(1, ViewAsSession::query()->count());
    }
}
