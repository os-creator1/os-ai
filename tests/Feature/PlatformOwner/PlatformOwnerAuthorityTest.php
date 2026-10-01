<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Library\ViewAs\ViewAsManager;
use App\Models\User;
use App\Models\WorkspaceEntitlementTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1 §1 — ONE rule for Platform Owner access, proven
 * from every side: the owner is let in; a Workspace owner, a member, an
 * Agency owner and a guest are not; neither a forged identifier nor View As
 * nor the admin "login as customer" mechanism confers authority; write
 * endpoints refuse independently of any hidden navigation.
 */
class PlatformOwnerAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    public function test_a_platform_owner_can_open_every_platform_owner_page(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        foreach ($this->platformOwnerGetUrls($workspace, $business) as $name => $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_customer_workspace_owner_cannot_open_any_platform_owner_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        foreach ($this->platformOwnerGetUrls($workspace, $business) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }
    }

    public function test_an_ordinary_workspace_member_cannot_open_any_platform_owner_page(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $memberCustomer = $this->createCustomer();
        $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($memberCustomer);

        foreach ($this->platformOwnerGetUrls($workspace, $business) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }
    }

    public function test_an_agency_owner_alone_cannot_open_any_platform_owner_page_even_for_their_own_client(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $this->authenticateAs($fixture['agencyOwner']);

        foreach ($this->platformOwnerGetUrls($fixture['clientWorkspace'], $fixture['clientBusiness']) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }

        foreach ($this->platformOwnerGetUrls($fixture['agencyWorkspace'], $fixture['agencyBusiness']) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }
    }

    public function test_a_non_owner_cannot_tell_a_real_target_from_a_missing_one(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        [$other] = $this->tenant(WorkspacePlanTier::Core, 'Other Co', 'Other WS');
        $this->authenticateAs($other);

        $missing = (string) \Illuminate\Support\Str::uuid();

        $real = $this->get(route('admin.workspaces.show', $workspace))->getStatusCode();
        $absent = $this->get(route('admin.workspaces.show', $missing))->getStatusCode();
        $this->assertSame($real, $absent, 'A non-owner must get the same refusal for a real and a missing Workspace.');

        $realBusiness = $this->get(route('admin.businesses.show', $business))->getStatusCode();
        $absentBusiness = $this->get(route('admin.businesses.show', 'nope-' . $business->uid))->getStatusCode();
        $this->assertSame($realBusiness, $absentBusiness, 'A non-owner must get the same refusal for a real and a missing Business.');

        $realPost = $this->post(route('admin.platform-owner.workspaces.restore-access', $workspace), ['reason' => 'x', 'confirm' => 1])->getStatusCode();
        $absentPost = $this->post(route('admin.platform-owner.workspaces.restore-access', $missing), ['reason' => 'x', 'confirm' => 1])->getStatusCode();
        $this->assertSame($realPost, $absentPost);
    }

    public function test_an_unauthenticated_visitor_cannot_open_any_platform_owner_page(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        foreach ($this->platformOwnerGetUrls($workspace, $business) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }

        $this->post(route('admin.platform-owner.workspaces.restore-access', $workspace), ['reason' => 'x', 'confirm' => 1])
            ->assertUnauthorized();
    }

    public function test_forged_identifiers_in_the_request_do_not_confer_authority(): void
    {
        // The attacker is an ordinary customer with their OWN active account;
        // the target is somebody else's locked Workspace.
        [$attacker] = $this->tenant(WorkspacePlanTier::Core, 'Attacker Co', 'Attacker WS');
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Victim Co', 'Victim WS');
        $this->lockForNonPayment($workspace);
        $this->authenticateAs($attacker);

        $forged = [
            'reason' => 'forged',
            'confirm' => 1,
            'is_admin' => 1,
            'user_id' => $this->platformAdminId(),
            'actor_user_id' => $this->platformAdminId(),
            'admin' => true,
        ];

        $this->post(route('admin.platform-owner.workspaces.restore-access', $workspace), $forged)->assertUnauthorized();
        $this->get(route('admin.platform-owner.overview', ['is_admin' => 1, 'user_id' => $this->platformAdminId()]))->assertUnauthorized();
        $this->patch(route('admin.businesses.status.update', $business), ['status' => 'inactive', 'reason' => 'forged', 'is_admin' => 1])
            ->assertUnauthorized();

        $this->assertNotNull(app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)->findByWorkspaceId($workspace->id)->locked_at, 'The forged restore must not have cleared the lock.');
        $this->assertSame(0, WorkspaceEntitlementTransition::query()->where('transition_type', 'access_restored')->count());
        $this->assertSame('active', $business->fresh()->status->value);
    }

    public function test_an_administrator_without_the_required_permission_cannot_run_the_write(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockForNonPayment($workspace);
        $this->actingAsPlatformOwner(['access backend', 'view workspace']);

        $this->post(route('admin.platform-owner.workspaces.restore-access', $workspace), ['reason' => 'No permission', 'confirm' => 1])
            ->assertUnauthorized();

        $this->assertNotNull(app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)->findByWorkspaceId($workspace->id)->locked_at);
    }

    public function test_an_open_view_as_frame_does_not_create_platform_owner_authority_for_an_agency_owner(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $this->authenticateAs($fixture['agencyOwner']);

        // A real cross-Workspace Agency View As session row, with the frame
        // carried in the request session exactly as the product does.
        $session = app(ViewAsManager::class)->startAgencyView(
            $fixture['agencyOwner']->user,
            $fixture['agencyWorkspace']->uid,
            $fixture['clientWorkspace']->uid,
            'Support walk-through',
        );
        $this->withSession([ViewAsManager::SESSION_KEY => $session->uid]);

        // Control: the frame really is open for the actor.
        $this->assertNotNull(app(ViewAsManager::class)->current($fixture['agencyOwner']->user));

        // Inside a View As frame the existing View As boundary answers with its
        // tenancy-safe 404 for a surface it does not allow; either refusal is
        // fail-closed. The assertion is that NEVER a 200 and never page content.
        foreach ($this->platformOwnerGetUrls($fixture['clientWorkspace'], $fixture['clientBusiness']) as $name => $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [401, 403, 404], "{$name} must be refused inside a View As frame.");
            $response->assertDontSee('Account access');
        }
    }

    public function test_platform_owner_pages_are_unavailable_inside_a_view_as_frame_even_for_an_admin_account(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        // Outside the frame the same account is let in...
        $this->get(route('admin.platform-owner.overview'))->assertOk();

        // ...and with a View As frame open in the session it is not.
        $this->withSession([ViewAsManager::SESSION_KEY => 'some-view-as-session-uid']);

        foreach ($this->platformOwnerGetUrls($workspace, $business) as $name => $url) {
            $this->get($url)->assertUnauthorized();
        }

        $this->post(route('admin.platform-owner.workspaces.restore-access', $workspace), ['reason' => 'x', 'confirm' => 1])
            ->assertUnauthorized();
    }

    public function test_the_admin_login_as_customer_mechanism_does_not_keep_platform_owner_pages_open(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $this->get(route('admin.customers.login_as', $customer->user))->assertRedirect();

        // The authenticated user is now the customer's own non-admin account.
        $this->assertSame((int) $customer->user_id, (int) auth()->id());
        $this->get(route('admin.platform-owner.overview'))->assertUnauthorized();
        $this->get(route('admin.workspaces.show', $workspace))->assertUnauthorized();
    }

    public function test_authority_service_reads_is_admin_from_the_database_not_from_a_model(): void
    {
        $authority = app(PlatformOwnerAuthority::class);
        $admin = User::create([
            'first_name' => 'Db', 'last_name' => 'Admin', 'email' => 'db-admin-' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $customer = $this->createCustomer();

        $authority->assertAdministrator((int) $admin->id);

        $this->expectException(AuthorizationException::class);
        $authority->assertAdministrator((int) $customer->user_id);
    }

    private function lockForNonPayment(\App\Models\Workspace $workspace): void
    {
        app(\App\Library\Entitlement\EntitlementManager::class)->lockForNonPayment($workspace, $this->platformAdminId(), 'Fixture lock.');
    }
}
