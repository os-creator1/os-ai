<?php

namespace Tests\Feature\V1Acceptance\AccountShell;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\V1Acceptance\AccountShell\Concerns\DrivesAccountJourneys;
use Tests\TestCase;

/**
 * V1 FINAL ACCEPTANCE 01 — journey F: membership and staff authority, on the
 * REAL merged Location ACL foundation, for a Business that was signed up and
 * paid for through the product's own routes.
 *
 * Two Locations (the signup Primary and one added later), one Contact at each,
 * and four actors: the owner, staff with every Location, staff with only
 * Location A, and an Agency owner who has no membership in this Workspace.
 *
 * Account/shell authority only. Every module's own Location matrix lives in its
 * module suite (ContactsLocationAclTest, ChatBoxLocationAclTest,
 * CrmOpportunityLocationAclTest, DocumentLocationAclTest, GlobalSearchTest…).
 */
class StaffAuthorityJourneyTest extends TestCase
{
    use RefreshDatabase;
    use DrivesAccountJourneys;
    use CreatesCrmFixtures;

    private const STAFF_PERMISSIONS = ['view_contact', 'update_contact', 'delete_contact', 'view_reports'];

    private Workspace $workspace;

    private Business $business;

    private Customer $owner;

    private BusinessLocation $locationA;

    private BusinessLocation $locationB;

    private Contacts $contactA;

    private Contacts $contactB;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->bootJourney();

        $account = $this->completeJourney('growth');
        $this->workspace = $account['workspace'];
        $this->business = $account['business'];
        $this->owner = $account['customer'];

        // Location A is the signup Primary Location; B is added afterwards.
        $this->locationA = BusinessLocation::query()->where('business_id', $this->business->id)->sole();
        $this->locationB = BusinessLocation::query()->create([
            'business_id' => $this->business->id,
            'name' => 'Second Studio',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        $this->contactA = $this->crmContact($this->business, ['FIRST_NAME' => 'Alma', 'LAST_NAME' => 'Aldous'], '14155550101');
        $this->contactA->forceFill(['location_id' => $this->locationA->id])->save();
        $this->contactB = $this->crmContact($this->business, ['FIRST_NAME' => 'Boris', 'LAST_NAME' => 'Bellweather'], '14155550202');
        $this->contactB->forceFill(['location_id' => $this->locationB->id])->save();
    }

    /** @return array{0: Customer, 1: \App\Models\WorkspaceMembership} */
    private function staff(LocationAccessScope $locations, array $grantedTo = [], WorkspaceMembershipRole $role = WorkspaceMembershipRole::Staff): array
    {
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, $role, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => $locations])->save();

        foreach ($grantedTo as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return [$staff, $membership];
    }

    private function scoped(): array
    {
        return [$this->workspace->uid, $this->business->uid];
    }

    /** @return array<int, int> */
    private function reachableLocationIds(Customer $actor): array
    {
        $ids = app(LocationAccessGuard::class)->accessibleLocationIdsForBusiness((int) $actor->user_id, $this->business);
        sort($ids);

        return $ids;
    }

    // =================================================================
    // OWNER
    // =================================================================

    public function test_the_owner_reaches_every_location_and_every_management_surface(): void
    {
        $this->authenticateAs($this->owner);

        $this->assertSame([$this->locationA->id, $this->locationB->id], $this->reachableLocationIds($this->owner));

        $this->get(route('customer.workspaces.businesses.locations.index', $this->scoped()))->assertOk();
        $this->get(route('customer.workspaces.team.show', [$this->workspace->uid]))->assertOk();
        $this->get(route('customer.workspaces.plan.show', [$this->workspace->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.usage-billing.show', $this->scoped()))->assertOk();

        // The Settings hub offers the management modules.
        $hub = $this->get(route('customer.workspaces.businesses.settings.show', $this->scoped()))->assertOk()->getContent();
        foreach (['locations', 'plan', 'team', 'usage-billing'] as $module) {
            $this->assertContains($module, $this->settingsHubModuleKeys($hub), "Owner must be offered [{$module}].");
        }

        // Both Contacts, at both Locations, open for the owner.
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactA->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactB->uid]))->assertOk();

        // Owner is not a Platform Owner.
        $this->get(route('admin.platform-owner.overview'))->assertUnauthorized();
    }

    // =================================================================
    // STAFF — all Locations
    // =================================================================

    public function test_staff_with_all_location_access_work_every_location_but_gain_no_owner_billing_or_admin_authority(): void
    {
        [$staff] = $this->staff(LocationAccessScope::All);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->assertSame([$this->locationA->id, $this->locationB->id], $this->reachableLocationIds($staff));

        // Operational records at both Locations.
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactA->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactB->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.people.index', $this->scoped()))->assertOk();

        // No owner / billing / team / admin authority, by direct URL.
        $this->post(route('customer.workspaces.plan.cancel', [$this->workspace->uid]), ['confirm' => '1'])->assertNotFound();
        $this->post(route('customer.workspaces.plan.change', [$this->workspace->uid]), ['tier' => 'agency', 'confirm' => '1'])->assertNotFound();
        $this->post(route('customer.workspaces.plan.resume', [$this->workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.plan.payment-method', [$this->workspace->uid]))->assertNotFound();
        $this->assertContains($this->get(route('customer.workspaces.team.show', [$this->workspace->uid]))->getStatusCode(), [401, 403, 404]);
        // Billing: restricted staff have none — the page is refused and every MUTATION too.
        $walletBefore = (array) DB::table('business_usage_wallets')->where('business_id', $this->business->id)->first();
        $payerBefore = (array) DB::table('business_payer_assignments')->where('business_id', $this->business->id)->first();

        // The page itself is refused, not merely hidden, and renders no financial data.
        $billing = $this->get(route('customer.workspaces.businesses.usage-billing.show', $this->scoped()));
        $billing->assertNotFound();

        foreach ([
            'usage-billing.payer' => ['payer_type' => 'workspace'],
            'usage-billing.spend-cap' => ['control' => 'business_spend_cap', 'monthly_spend_cap_micro' => 1000000],
            'usage-billing.top-up.initiate' => ['amount' => 25],
            'usage-billing.auto-recharge.configure' => ['enabled' => 1],
            'usage-billing.payment-method.setup-intent' => [],
        ] as $name => $payload) {
            $response = $this->post(route('customer.workspaces.businesses.' . $name, $this->scoped()), $payload);
            $this->assertNotSame(200, $response->getStatusCode(), "Staff billing mutation [{$name}] must not succeed.");
        }

        $this->assertEquals($walletBefore, (array) DB::table('business_usage_wallets')->where('business_id', $this->business->id)->first(), 'Staff changed nothing on the wallet.');
        $this->assertEquals($payerBefore, (array) DB::table('business_payer_assignments')->where('business_id', $this->business->id)->first(), 'Staff changed nothing about the payer.');
        $this->get(route('admin.platform-owner.overview'))->assertUnauthorized();

        // …and the Settings hub does not offer them either (hidden AND refused).
        $hub = $this->get(route('customer.workspaces.businesses.settings.show', $this->scoped()))->assertOk()->getContent();
        foreach (['plan', 'team', 'usage-billing'] as $module) {
            $this->assertNotContains($module, $this->settingsHubModuleKeys($hub), "Staff must not be offered [{$module}].");
        }

        $this->assertNotNull($this->workspace->fresh()->platformSubscription, 'The subscription is untouched.');
        $this->assertFalse((bool) $this->workspace->fresh()->platformSubscription->cancel_at_period_end);
    }

    // =================================================================
    // STAFF — selected Location A only
    // =================================================================

    public function test_staff_with_one_selected_location_reach_that_location_and_cannot_reach_the_other_by_any_route(): void
    {
        [$staff] = $this->staff(LocationAccessScope::Selected, [$this->locationA]);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->assertSame([$this->locationA->id], $this->reachableLocationIds($staff));

        $group = $this->contactA->contactGroup;
        $this->assertSame($group->id, $this->contactB->contactGroup->id, 'Both Contacts share the one group: the Location is the only difference.');

        // -- Location A: reachable ------------------------------------------
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactA->uid]))->assertOk();
        $this->postJson(route('customer.workspaces.businesses.contact.status', [...$this->scoped(), $group->uid]), ['id' => $this->contactA->uid])
            ->assertJson(['status' => 'success']);
        $this->assertNotEmpty($this->searchResults('Alma')['contacts'] ?? [], 'Global Search finds the Location A Contact.');

        // -- Location B: not by normal navigation (the Contacts directory) --
        $directory = $this->get(route('customer.workspaces.businesses.people.index', $this->scoped()))->assertOk()->getContent();
        $this->assertStringContainsString('Alma', $directory);
        $this->assertStringNotContainsString('Bellweather', $directory, 'The Contacts list must not list a Location B Contact.');
        $this->assertStringNotContainsString('14155550202', $directory);
        $searched = $this->get(route('customer.workspaces.businesses.people.index', $this->scoped()) . '?q=Bellweather')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->contactB->uid, $searched, 'A directory search must not reveal a Location B Contact.');
        $this->assertStringNotContainsString('14155550202', $searched);

        // -- not by direct URL ----------------------------------------------
        $this->assertContains(
            $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactB->uid]))->getStatusCode(),
            [401, 403, 404],
            'A direct URL to a Location B Contact profile must be refused.'
        );

        // -- not by a forged id / uid in an AJAX action ----------------------
        $this->postJson(route('customer.workspaces.businesses.contact.status', [...$this->scoped(), $group->uid]), ['id' => $this->contactB->uid])
            ->assertJson(['status' => 'error']);
        $this->postJson(route('customer.workspaces.businesses.contact.delete', [...$this->scoped(), $group->uid]), ['id' => $this->contactB->uid])
            ->assertJson(['status' => 'error']);
        $this->assertNotNull(Contacts::query()->find($this->contactB->id), 'A refused delete has no side effect.');

        // -- not as a search result -----------------------------------------
        $this->assertSame([], $this->searchResults('Bellweather')['contacts'] ?? [], 'Global Search yields nothing for a Location B Contact.');

        // -- and the Location management surfaces do not hand B over ---------
        $this->assertContains(
            $this->get(route('customer.workspaces.businesses.locations.edit', [...$this->scoped(), $this->locationB->uid]))->getStatusCode(),
            [401, 403, 404],
        );
    }

    // =================================================================
    // Agency membership does not widen a client Workspace
    // =================================================================

    public function test_agency_membership_does_not_widen_an_ordinary_client_workspace(): void
    {
        $agency = $this->completeJourney('agency', ['email' => 'agency-' . uniqid() . '@example.test']);
        $agencyOwner = $agency['customer'];

        // An Agency owner has no membership in this (non-managed) Workspace and
        // so reaches none of it, whatever tier their own account carries.
        Auth::logout();
        $this->authenticateAs($agencyOwner);

        $this->assertSame([], $this->reachableLocationIds($agencyOwner));
        $this->get(route('customer.workspaces.businesses.people.index', $this->scoped()))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.locations.index', $this->scoped()))->assertNotFound();
        $this->get(route('customer.workspaces.plan.show', [$this->workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.show', [$this->workspace->uid]))->assertNotFound();
        $this->get(route('admin.platform-owner.overview'))->assertUnauthorized();

        // An Agency's own team member holds no membership here either.
        $teamMember = $this->createCustomer();
        $this->member(Workspace::query()->findOrFail($agency['workspace']->id), $teamMember->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($teamMember);

        $this->assertSame([], $this->reachableLocationIds($teamMember));
        $this->get(route('customer.workspaces.businesses.people.show', [...$this->scoped(), $this->contactA->uid]))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function searchResults(string $q): array
    {
        $url = route('customer.workspaces.businesses.search', $this->scoped()) . '?q=' . urlencode($q);

        return $this->getJson($url)->assertOk()->json('results') ?? [];
    }
}
