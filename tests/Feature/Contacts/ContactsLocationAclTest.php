<?php

namespace Tests\Feature\Contacts;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B Part 3/§6 — LocationAccessGuard wired into
 * ContactsController's single-Contact read/write actions
 * (editContact/updateContact/deleteContact/updateContactStatus), reached
 * through the Business-addressable `...ForBusiness` route family (which
 * calls the exact same underlying methods via __call(), per the
 * controller's own documented shim).
 *
 * The route parameter named `contact` identifies the ContactGroup, never
 * the leaf Contact (this session's own prior PR #302 finding) — every test
 * below resolves the real leaf Contact by its own `contact_id`/`id`
 * request parameter, exactly as production does.
 *
 * MECHANICAL FINDING (implementation-time, unrelated to Location ACL):
 * editContact()/updateContact() additionally filter the leaf Contact by
 * `customer_id = Auth::user()->id` — the literal acting user's own id, not
 * the Business's owning customer. This is pre-existing legacy behaviour
 * that makes both actions effectively unreachable for any staff member
 * (whose own user id never equals the Contact's owning customer_id),
 * regardless of Location ACL. Location ACL is wired into both exactly the
 * same way as the other two actions, but the adversarial Selected-scope
 * matrix below is driven through updateContactStatus()/deleteContact()
 * (which carry no such filter and are genuinely staff-reachable);
 * editContact()/updateContact() are proven only for the owner, whose
 * access is unaffected by either finding.
 */
class ContactsLocationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const STAFF_PERMISSIONS = ['view_contact', 'update_contact', 'delete_contact'];

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    public function test_selected_location_scope_with_correct_grant_can_update_status(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $location->id])->save();
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'success']);
    }

    public function test_selected_location_scope_with_no_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $location->id])->save();
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'error']);

        $this->postJson(route('customer.workspaces.businesses.contact.delete', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'error']);

        $this->assertNotNull(Contacts::find($subscriber->id), 'A denied delete must have no side effect.');
    }

    public function test_selected_location_scope_with_only_a_sibling_locations_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $siblingLocation = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $location->id])->save();
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $siblingLocation);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'error']);
    }

    public function test_all_location_scope_reaches_any_location_in_the_business(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $location->id])->save();
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'success']);
    }

    public function test_owner_reaches_any_location_regardless_of_membership(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $location->id])->save();
        $group = $subscriber->contactGroup;

        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'success']);

        // editContact()/updateContact() also unaffected for the owner —
        // the one route family where Location ACL is wired but the
        // pre-existing customer_id filter only ever lets the owner
        // through anyway.
        $this->get(route('customer.workspaces.businesses.contact.edit', [$workspace->uid, $business->uid, $group->uid, 'contact_id' => $subscriber->uid]))
            ->assertOk()
            ->assertDontSee(__('locale.contacts.contact_not_found'));
    }

    public function test_a_null_location_contact_is_unaffected_by_location_acl(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $subscriber = $this->crmContact($business);
        $this->assertNull($subscriber->location_id);
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'success']);
    }

    public function test_foreign_business_remains_denied_even_with_a_location_grant_elsewhere(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->crmTenant('A ' . uniqid(), 'WS A ' . uniqid());
        [$ownerB, $businessB, $workspaceB] = $this->crmTenant('B ' . uniqid(), 'WS B ' . uniqid());
        $locationB = $this->location($businessB);
        $subscriberB = $this->crmContact($businessB);
        $subscriberB->forceFill(['location_id' => $locationB->id])->save();
        $groupB = $subscriberB->contactGroup;

        $staff = $this->createCustomer();
        $membershipA = $this->member($workspaceA, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membershipA->forceFill(['location_access_scope' => LocationAccessScope::All])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        // Wrong Workspace/Business pair for this group entirely.
        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspaceA->uid, $businessA->uid, $groupB->uid]), [
            'id' => $subscriberB->uid,
        ])->assertNotFound();
    }

    public function test_a_grant_for_an_unrelated_location_cannot_substitute_for_the_persisted_one(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $trueLocation = $this->location($business);
        $otherLocation = $this->location($business);
        $subscriber = $this->crmContact($business);
        $subscriber->forceFill(['location_id' => $trueLocation->id])->save();
        $group = $subscriber->contactGroup;

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $otherLocation);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->postJson(route('customer.workspaces.businesses.contact.status', [$workspace->uid, $business->uid, $group->uid]), [
            'id' => $subscriber->uid,
        ])->assertJson(['status' => 'error']);
    }
}
