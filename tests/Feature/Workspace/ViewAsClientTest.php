<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — View as client (contract §5.5):
 * T-VIEW-1 (audit on entry and exit, identity unchanged), T-VIEW-2 (TTL
 * expiry returns to the Agency frame), T-VIEW-3 (each prohibited action is
 * refused and audited). T-VIEW-4 lives in CustomerContextSecurityTest.
 */
class ViewAsClientTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_view_as_writes_an_audit_row_on_entry_and_exit_and_never_changes_the_actor(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        $actorId = (int) $agency->user_id;

        $this->startViewAs($workspace, $client, 'Support ticket 42')->assertRedirect(route('user.home'));

        $row = ViewAsSession::query()->sole();
        $this->assertSame($actorId, (int) $row->actor_user_id);
        $this->assertSame((int) $workspace->id, (int) $row->workspace_id);
        $this->assertSame((int) $client->id, (int) $row->business_id);
        $this->assertSame('Support ticket 42', $row->reason);
        $this->assertNotNull($row->started_at);
        $this->assertNull($row->ended_at);
        $this->assertSame(ViewAsManager::DEFAULT_TTL_MINUTES, (int) round($row->started_at->diffInMinutes($row->expires_at)));

        $home = $this->home()->assertOk();
        $this->assertSame($actorId, Auth::id(), 'Auth::id() is the Agency actor throughout (S-5).');
        $home->assertSee('data-role="view-as-banner"', false);
        $home->assertSee('Viewing Client Bakery as a client', false);
        $home->assertSee($agency->user->displayName(), false);
        $home->assertSee('Exit client view', false);
        $this->assertStringContainsString('Client Bakery', $this->shellText($home->getContent()));
        $this->assertContains('analytics', $this->menuKeys($home->getContent()), 'The viewed client renders in the Business frame.');
        $home->assertDontSee('customer-context-switcher-toggle', false);

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));

        $row->refresh();
        $this->assertNotNull($row->ended_at);
        $this->assertSame(ViewAsSession::END_REASON_EXIT, $row->end_reason);
        $this->assertSame($actorId, Auth::id(), 'Exit never logs the actor out.');
        $this->home()->assertOk()->assertDontSee('data-role="view-as-banner"', false);
    }

    public function test_view_as_expires_at_its_ttl_and_returns_the_actor_to_the_agency_frame(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        // Contract 13 remediation: reaching the Agency account frame to
        // return to used to be incidental (a second sibling Business made
        // the frame resolver's business selection ambiguous), which is now
        // impossible under one-Business-per-Workspace. The context
        // switcher's own deliberate "account" choice (switchToAccount(),
        // Lane E) is the current way to stand in that frame regardless of
        // Business count, and it is what T-VIEW-2 needs restored on expiry.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $this->travel(ViewAsManager::DEFAULT_TTL_MINUTES - 1)->minutes();
        $this->home()->assertOk()->assertSee('data-role="view-as-banner"', false);

        $this->travel(2)->minutes();
        $expired = $this->home()->assertOk();
        $expired->assertDontSee('data-role="view-as-banner"', false);
        $this->assertContains('accounts', $this->menuKeys($expired->getContent()), 'Expiry returns the actor to the Agency account frame (T-VIEW-2).');

        $row = ViewAsSession::query()->sole();
        $this->assertNotNull($row->ended_at);
        $this->assertSame(ViewAsSession::END_REASON_EXPIRED, $row->end_reason);
        $this->travelBack();
    }

    public function test_each_prohibited_action_is_refused_and_audited_while_viewing(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $other = $this->anotherReachableBusiness($agency);
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $prohibited = [
            'payer' => fn () => $this->post(route('customer.workspaces.businesses.usage-billing.payer', [$workspace->uid, $client->uid]), ['payer_type' => 'business']),
            // Funding/spend controls share the usage-billing prefix; the
            // spend-cap route is used because the top-up controller's
            // constructor needs a Stripe secret this test environment does
            // not carry (controllers are instantiated before middleware).
            'funding controls' => fn () => $this->post(route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspace->uid, $client->uid]), ['monthly_spend_cap_micro' => 1000000]),
            'staff' => fn () => $this->post(route('customer.workspaces.members.store', $workspace->uid), ['user_uid' => 'x', 'role' => 'staff']),
            'plan' => fn () => $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Renamed']),
            'provider' => fn () => $this->get(route('customer.workspaces.businesses.channels.index', [$workspace->uid, $client->uid])),
            'delete' => fn () => $this->delete(route('customer.workspaces.businesses.contacts.destroy', [$workspace->uid, $client->uid, 'any'])),
            'another view-as' => fn () => $this->startViewAs($other->workspace, $other),
            'switch' => fn () => $this->switchTo($other->workspace, $other),
        ];

        $expectedRefusals = 0;

        foreach ($prohibited as $label => $attempt) {
            $response = $attempt();
            $response->assertRedirect(route('user.home'));
            $response->assertSessionHas('status', 'warning');
            $response->assertSessionHas('message', fn (string $message) => str_contains($message, 'not available while you are viewing this client account'));

            $expectedRefusals++;
            $row = ViewAsSession::query()->whereNull('ended_at')->sole();
            $this->assertCount($expectedRefusals, $row->refusals ?? [], "{$label}: the refusal must be audited.");
        }

        $names = array_column(ViewAsSession::query()->sole()->refusals, 'route');
        $this->assertContains('customer.workspaces.businesses.usage-billing.payer', $names);
        $this->assertContains('customer.workspaces.members.store', $names);
        $this->assertContains('customer.view-as.start', $names);
        $this->assertContains('customer.context.business.switch', $names);

        // Reading remains possible: the client's own pages still render.
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $client->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $client->uid]))->assertOk();
        $this->assertSame(1, ViewAsSession::query()->count(), 'No second session was ever created.');
    }

    public function test_only_a_workspace_owner_or_active_admin_may_start_a_view(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff);
        $this->startViewAs($workspace, $client)->assertNotFound();
        $this->assertSame(0, ViewAsSession::query()->count());

        $inactiveAdmin = $this->createCustomer();
        $this->member($workspace, $inactiveAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);
        $this->authenticateAs($inactiveAdmin);
        $this->startViewAs($workspace, $client)->assertNotFound();

        [$stranger] = $this->tenant(WorkspacePlanTier::Agency, 'Stranger Client', 'Stranger Agency');
        $this->authenticateAs($stranger);
        $this->startViewAs($workspace, $client)->assertNotFound();
        $this->assertSame(0, ViewAsSession::query()->count());

        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));
        $this->assertSame(1, ViewAsSession::query()->count());
    }

    public function test_starting_again_replaces_the_previous_session_and_logout_ends_it(): void
    {
        [$agency, $first, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $second = $this->anotherReachableBusiness($agency);
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $first)->assertRedirect(route('user.home'));
        $this->post(route('customer.view-as.exit'));
        $this->startViewAs($second->workspace, $second)->assertRedirect(route('user.home'));

        $rows = ViewAsSession::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(ViewAsSession::END_REASON_EXIT, $rows[0]->end_reason);
        $this->assertNull($rows[1]->ended_at);
        $this->assertSame((int) $second->id, (int) $rows[1]->business_id);

        $this->post(route('logout'));

        $this->assertSame(ViewAsSession::END_REASON_LOGOUT, ViewAsSession::query()->findOrFail($rows[1]->id)->end_reason);
    }

    // =========================================================================
    // PR #302 correction 3, findings E and F — the account-lock boundary
    // during an active View-as session.
    // =========================================================================

    public function test_a_locked_viewed_workspace_reaches_the_narrowed_locked_screen_during_view_as(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);

        // The gate redirects here (finding E's own trigger); the locked
        // screen must be classified reachable during View-as at all
        // (previously Unclassified -> Denied -> 404) and must show facts
        // about the VIEWED Workspace -- never some other, never a
        // fallback.
        $this->home()->assertRedirect(route('customer.account-locked.show'));

        $lockedScreen = $this->get(route('customer.account-locked.show'));
        $lockedScreen->assertOk();
        $lockedScreen->assertSee('suspended');
        $lockedScreen->assertSee('Northwind Agency');
    }

    public function test_view_as_exit_remains_reachable_while_the_viewed_workspace_is_locked(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);

        // Without finding F's fix this route has no {workspaceUid}, falls
        // to the workspace-agnostic path, resolves the locked viewed
        // Workspace, and the account-lock gate redirects to the locked
        // screen before ExitViewAsAction ever runs.
        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));

        $row = ViewAsSession::query()->sole();
        $this->assertNotNull($row->ended_at, 'Exit must still end the View-as session while the viewed Workspace is locked.');
        $this->assertSame(ViewAsSession::END_REASON_EXIT, $row->end_reason);
        $this->assertSame((int) $agency->user_id, Auth::id(), 'Exit never logs the actor out.');
    }

    /**
     * Contract 13 remediation (Category C): "another Business the same
     * actor can reach" used to be a second sibling in the SAME Workspace —
     * a shape businesses_workspace_id_unique now forbids. What these tests
     * actually need is a genuinely different, ordinarily-reachable Business
     * to attempt switching/viewing to while a View As session is active
     * (proving the prohibition/session-replacement behavior), which a
     * separate Workspace with an ordinary Admin membership provides just
     * as well — start()'s own authorization never depended on same-
     * Workspace siblings, only on owner/active-Admin status of whichever
     * Workspace holds the target Business.
     */
    private function anotherReachableBusiness(Customer $agency, string $name = 'Client Florist'): Business
    {
        $another = $this->createIndependentWorkspaceBusiness(businessName: $name, workspaceName: $name . ' Workspace');
        $this->member($another['workspace'], $agency->user, WorkspaceMembershipRole::Admin);

        return $another['business'];
    }

    private function lockWorkspace(Workspace $workspace, WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'PR #302 correction 3 fixture lock.');
    }
}
