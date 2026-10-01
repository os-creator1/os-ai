<?php

namespace Tests\Feature\Crm\Tags;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Crm\TagManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contact Tags foundation §6/§7/§8 — the customer HTTP surface: tenancy,
 * authorization, Location ACL, and bounded query behavior.
 */
class ContactTagsHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function manager(): TagManager
    {
        return app(TagManager::class);
    }

    private function location(Business $business): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    private function authenticateAsStaffUser(User $user, array $permissions): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

    private function staffUser(Workspace $workspace, Business $business, WorkspaceBusinessAccessScope $businessScope = WorkspaceBusinessAccessScope::All, LocationAccessScope $locationScope = LocationAccessScope::All): array
    {
        $user = User::create([
            'first_name' => 'Staff', 'last_name' => 'Member',
            'email' => 'staff-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $membership = $this->member($workspace, $user, WorkspaceMembershipRole::Staff, $businessScope);
        $membership->forceFill(['location_access_scope' => $locationScope->value])->save();

        if ($businessScope === WorkspaceBusinessAccessScope::Selected) {
            $this->assign($membership, $business);
        }

        return [$user, $membership];
    }

    private function routeArgs(Workspace $workspace, Business $business, array $extra = []): array
    {
        return [$workspace->uid, $business->uid, ...$extra];
    }

    // =================================================================
    // Tenancy / authorization
    // =================================================================

    public function test_the_owner_can_create_rename_and_archive_a_tag(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.tags.store', $this->routeArgs($workspace, $business)), ['name' => 'VIP'])
            ->assertRedirect();

        $tag = Tag::query()->where('business_id', $business->id)->where('name', 'VIP')->firstOrFail();

        $this->post(route('customer.workspaces.businesses.tags.rename', $this->routeArgs($workspace, $business, [$tag->uid])), ['name' => 'Important'])
            ->assertRedirect();
        $this->assertSame('Important', $tag->fresh()->name);

        $this->post(route('customer.workspaces.businesses.tags.archive', $this->routeArgs($workspace, $business, [$tag->uid])))
            ->assertRedirect();
        $this->assertTrue($tag->fresh()->isArchived());
    }

    public function test_the_owner_can_attach_and_detach_a_tag_by_phone(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business, [], '14155551234');

        $this->post(route('customer.workspaces.businesses.tags.attach', $this->routeArgs($workspace, $business, [$tag->uid])), ['phone' => '+1 (415) 555-1234'])
            ->assertRedirect();

        $this->assertTrue($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));

        $this->delete(route('customer.workspaces.businesses.tags.detach', $this->routeArgs($workspace, $business, [$tag->uid, $contact->uid])))
            ->assertRedirect();

        $this->assertFalse($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));
    }

    public function test_a_foreign_business_tag_uid_is_refused_with_404(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $this->authenticateAs($customerA);

        $tagB = $this->manager()->createTag($businessB, 'VIP');

        $this->post(route('customer.workspaces.businesses.tags.archive', $this->routeArgs($workspaceA, $businessA, [$tagB->uid])))
            ->assertNotFound();
    }

    public function test_a_staff_member_without_business_access_is_refused_with_404(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        [, $otherBusiness, $otherWorkspace] = $this->crmTenant('Elsewhere', 'Elsewhere Workspace');
        [$outsider] = $this->staffUser($otherWorkspace, $otherBusiness);
        $this->authenticateAsStaffUser($outsider, ['view_contact', 'update_contact']);

        $this->get(route('customer.workspaces.businesses.tags.index', $this->routeArgs($workspace, $business)))
            ->assertNotFound();
    }

    // =================================================================
    // Location ACL
    // =================================================================

    public function test_selected_scope_staff_without_the_contacts_location_are_refused(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $locationA = $this->location($business);
        $locationB = $this->location($business);
        [$staff, $membership] = $this->staffUser($workspace, $business, WorkspaceBusinessAccessScope::Selected, LocationAccessScope::Selected);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locationB);

        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business, [], '14155559876');
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $this->authenticateAsStaffUser($staff, ['view_contact', 'update_contact']);

        $this->post(route('customer.workspaces.businesses.tags.attach', $this->routeArgs($workspace, $business, [$tag->uid])), ['phone' => '14155559876'])
            ->assertNotFound();

        $this->assertFalse($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));
    }

    public function test_selected_scope_staff_with_the_contacts_location_succeed(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $locationA = $this->location($business);
        [$staff, $membership] = $this->staffUser($workspace, $business, WorkspaceBusinessAccessScope::Selected, LocationAccessScope::Selected);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locationA);

        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business, [], '14155559876');
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $this->authenticateAsStaffUser($staff, ['view_contact', 'update_contact']);

        $this->post(route('customer.workspaces.businesses.tags.attach', $this->routeArgs($workspace, $business, [$tag->uid])), ['phone' => '14155559876'])
            ->assertRedirect();

        $this->assertTrue($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));
    }

    // =================================================================
    // Query budget
    // =================================================================

    public function test_the_tags_index_query_count_does_not_grow_with_tag_count(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        foreach (range(1, 3) as $i) {
            $this->manager()->createTag($business, "Tag {$i}");
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('customer.workspaces.businesses.tags.index', $this->routeArgs($workspace, $business)))->assertSuccessful();
        $withThree = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(4, 15) as $i) {
            $this->manager()->createTag($business, "Tag {$i}");
        }

        DB::flushQueryLog();
        $this->get(route('customer.workspaces.businesses.tags.index', $this->routeArgs($workspace, $business)))->assertSuccessful();
        $withFifteen = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertGreaterThan(0, $withThree);
        // Not exact equality: unrelated per-run variance (e.g. a relation
        // lazy-load's cache warmth) can shift the total by a query or two
        // between any two runs, tag count aside. A genuine one-query-per-tag
        // defect would show roughly +12 here; this asserts nothing close to
        // that scale ever shows up.
        $this->assertLessThanOrEqual(
            $withThree + 3,
            $withFifteen,
            "Listing tags must not cost one query per tag: {$withThree} queries for 3 tags vs {$withFifteen} for 15.",
        );
    }
}
