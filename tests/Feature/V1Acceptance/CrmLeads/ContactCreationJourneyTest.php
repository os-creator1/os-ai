<?php

namespace Tests\Feature\V1Acceptance\CrmLeads;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V1Acceptance\CrmLeads\Concerns\CrmLeadsFixtures;
use Tests\TestCase;

/**
 * V1 Final Acceptance 02, journeys A and E — a real owner creates Contacts through
 * the customer path (Contacts -> "+ Add contact"), and the saved data
 * (the group's custom fields) behaves the way a Business expects.
 *
 *  A. The Contact belongs to the intended Business and resolves to the one Location
 *     V1 can prove; a foreign Business / Location / group / customer id in the
 *     request is ignored; one phone number is one Contact per list; the uid is
 *     stable.
 *  E. Custom fields ARE on the V1 customer surface (a list's "Manage fields",
 *     rendered on each person's profile): read and write, isolated per Business,
 *     attached to the right Contact, with stale or foreign field ids refused.
 */
class ContactCreationJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CrmLeadsFixtures;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private ContactGroups $group;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($this->owner);

        // The real first step: a Business with no list gets its first one.
        $this->post(route('customer.workspaces.businesses.people.first-list', [$this->workspace->uid, $this->business->uid]), ['next' => 'add'])->assertRedirect();
        $this->group = ContactGroups::query()->where('business_id', $this->business->id)->sole();
        // A Business has its Primary Location from signup; Contacts are Location-bound.
        $this->primary = $this->location($this->business, 'Primary');
    }

    private BusinessLocation $primary;

    private function storeUrl(?ContactGroups $group = null, ?Workspace $workspace = null, ?Business $business = null): string
    {
        return route('customer.workspaces.businesses.contact.store', [($workspace ?? $this->workspace)->uid, ($business ?? $this->business)->uid, ($group ?? $this->group)->uid]);
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace, 3: ContactGroups} */
    private function rival(): array
    {
        [$owner, $business, $workspace] = $this->crmTenant('Rival Studio', 'Rival');
        $this->authenticateAs($owner);
        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]), ['next' => 'add'])->assertRedirect();
        $group = ContactGroups::query()->where('business_id', $business->id)->sole();
        $this->authenticateAs($this->owner);

        return [$owner, $business, $workspace, $group];
    }

    // ------------------------------------------------------------------ A

    public function test_the_owner_creates_a_contact_in_the_intended_business_at_its_single_location(): void
    {
        $location = $this->primary;

        $response = $this->post($this->storeUrl(), ['PHONE' => '+1 (415) 555-0111', 'FIRST_NAME' => 'Jordan', 'LAST_NAME' => 'Rivera']);

        $contact = Contacts::query()->where('business_id', $this->business->id)->sole();
        $this->assertSame('14155550111', (string) $contact->phone, 'the number is stored normalized');
        $this->assertSame((int) $this->group->id, (int) $contact->group_id);
        $this->assertSame((int) $this->business->customer_id, (int) $contact->customer_id);
        $this->assertSame((int) $location->id, (int) $contact->location_id, 'exactly one Active Location: that one');
        $this->assertNotSame('', (string) $contact->uid);

        $response->assertRedirect(route('customer.workspaces.businesses.people.show', [$this->workspace->uid, $this->business->uid, $contact->uid]));
        $this->get($this->peopleUrl($this->workspace, $this->business, $contact->uid))->assertOk()->assertSee('Jordan')->assertSee('Rivera');
    }

    public function test_with_several_active_locations_the_actor_must_choose_one_of_theirs(): void
    {
        $second = $this->location($this->business, 'Second');
        $rivalLocation = $this->location($this->rival()[1], 'Rival Downtown');

        // Not chosen, forged and foreign: refused, nothing created.
        $this->post($this->storeUrl(), ['PHONE' => '14155550121', 'FIRST_NAME' => 'None'])->assertSessionHasErrors('location');
        $this->post($this->storeUrl(), ['PHONE' => '14155550121', 'FIRST_NAME' => 'Forged', 'location' => 'no-such-uid'])->assertSessionHasErrors('location');
        $this->post($this->storeUrl(), ['PHONE' => '14155550121', 'FIRST_NAME' => 'Foreign', 'location' => $rivalLocation->uid])->assertSessionHasErrors('location');
        $this->assertSame(0, Contacts::query()->count());

        $this->post($this->storeUrl(), ['PHONE' => '14155550122', 'FIRST_NAME' => 'Chosen', 'location' => $second->uid])->assertRedirect();
        $this->assertSame((int) $second->id, (int) Contacts::query()->sole()->location_id);
        $this->get(route('customer.workspaces.businesses.contact.create', [$this->workspace->uid, $this->business->uid, $this->group->uid]))->assertOk()->assertSee('name="location"', false);
    }

    public function test_restricted_staff_can_only_choose_their_own_locations_and_zero_locations_refuses(): void
    {
        $second = $this->location($this->business, 'Second');
        $staff = $this->staffAt($this->workspace, $second);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        // Exactly one reachable Location: used automatically; another one is refused.
        $this->post($this->storeUrl(), ['PHONE' => '14155550123', 'FIRST_NAME' => 'Mine'])->assertRedirect();
        $this->assertSame((int) $second->id, (int) Contacts::query()->sole()->location_id);
        $this->post($this->storeUrl(), ['PHONE' => '14155550124', 'FIRST_NAME' => 'Other', 'location' => $this->primary->uid])->assertSessionHasErrors('location');
        $this->assertSame(1, Contacts::query()->count());

        // No Location at all: no Location-less Contact.
        [$owner, $bare, $bareWorkspace] = $this->crmTenant('Bare Co', 'Bare');
        $this->authenticateAs($owner);
        $this->post(route('customer.workspaces.businesses.people.first-list', [$bareWorkspace->uid, $bare->uid]), ['next' => 'add']);
        $bareGroup = ContactGroups::query()->where('business_id', $bare->id)->sole();
        $this->post($this->storeUrl($bareGroup, $bareWorkspace, $bare), ['PHONE' => '14155550125', 'FIRST_NAME' => 'Nowhere'])->assertSessionHasErrors('location');
        $this->assertSame(0, Contacts::query()->where('business_id', $bare->id)->count());
    }

    public function test_identity_is_location_local_across_lists_and_formatting(): void
    {
        $second = $this->location($this->business, 'Second');
        $otherList = ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Other list', 'status' => true]);

        $this->post($this->storeUrl(), ['PHONE' => '14155550191', 'FIRST_NAME' => 'First', 'location' => $this->primary->uid])->assertRedirect();

        // Same Location, other list, other formatting: the same Contact, refused.
        $this->post($this->storeUrl($otherList), ['PHONE' => '+1 (415) 555-0191', 'FIRST_NAME' => 'Dup', 'location' => $this->primary->uid])->assertSessionHasErrors('PHONE');
        $this->assertSame(1, Contacts::query()->count());

        // Another Location: a separate Contact is valid, even in the same list.
        $this->post($this->storeUrl(), ['PHONE' => '14155550191', 'FIRST_NAME' => 'Second', 'location' => $second->uid])->assertRedirect();
        $this->assertSame(2, Contacts::query()->where('phone', '14155550191')->count());

        // Another Business: separate too.
        [$rivalOwner, $rivalBusiness, $rivalWorkspace, $rivalGroup] = $this->rival();
        $this->location($rivalBusiness, 'Rival');
        $this->authenticateAs($rivalOwner);
        $this->post($this->storeUrl($rivalGroup, $rivalWorkspace, $rivalBusiness), ['PHONE' => '14155550191', 'FIRST_NAME' => 'Rival'])->assertRedirect();
        $this->assertSame(3, Contacts::query()->where('phone', '14155550191')->count());
    }
    public function test_forged_business_location_group_and_customer_ids_in_the_request_are_ignored(): void
    {
        [$rivalOwner, $rivalBusiness, $rivalWorkspace, $rivalGroup] = $this->rival();
        $rivalLocation = $this->location($rivalBusiness, 'Rival Downtown');

        $this->post($this->storeUrl(), [
            'PHONE' => '14155550131',
            'FIRST_NAME' => 'Forged',
            'business_id' => $rivalBusiness->id,
            'location_id' => $rivalLocation->id,
            'group_id' => $rivalGroup->id,
            'customer_id' => $rivalOwner->id,
            'uid' => 'attacker-chosen-uid',
            'status' => 'unsubscribe',
        ]);

        $contact = Contacts::query()->where('phone', '14155550131')->sole();
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->group->id, (int) $contact->group_id);
        $this->assertSame((int) $this->business->customer_id, (int) $contact->customer_id);
        $this->assertNotSame((int) $rivalLocation->id, (int) $contact->location_id);
        $this->assertNotSame('attacker-chosen-uid', $contact->uid);
        $this->assertSame(0, Contacts::query()->where('business_id', $rivalBusiness->id)->count());
        $this->assertSame(0, Contacts::query()->where('group_id', $rivalGroup->id)->count());
    }

    public function test_another_business_cannot_add_to_this_businesss_list_or_name_it_in_its_own_route(): void
    {
        [$rivalOwner, $rivalBusiness, $rivalWorkspace] = $this->rival();
        $this->authenticateAs($rivalOwner);

        // My list, through my route and through theirs: the same 404 as an unknown uid.
        $this->post($this->storeUrl(), ['PHONE' => '14155550141', 'FIRST_NAME' => 'Intruder'])->assertNotFound();
        $this->post($this->storeUrl(null, $rivalWorkspace, $rivalBusiness), ['PHONE' => '14155550142', 'FIRST_NAME' => 'Intruder'])->assertNotFound();
        $this->get(route('customer.workspaces.businesses.contact.create', [$this->workspace->uid, $this->business->uid, $this->group->uid]))->assertNotFound();

        $this->assertSame(0, Contacts::query()->count());
    }

    public function test_one_phone_number_is_one_contact_per_list_whatever_its_formatting(): void
    {
        $this->post($this->storeUrl(), ['PHONE' => '14155550151', 'FIRST_NAME' => 'First'])->assertRedirect();
        $original = Contacts::query()->where('phone', '14155550151')->sole();

        foreach (['14155550151', '+1 (415) 555-0151', '1-415-555-0151'] as $variant) {
            $this->post($this->storeUrl(), ['PHONE' => $variant, 'FIRST_NAME' => 'Again'])->assertSessionHasErrors('PHONE');
        }

        $this->assertSame(1, Contacts::query()->where('phone', '14155550151')->count());
        $this->assertSame($original->uid, Contacts::query()->where('phone', '14155550151')->value('uid'));
        $this->assertSame('First', $this->customField($original->fresh(), 'FIRST_NAME'), 'a refused duplicate never overwrites the first');
    }

    public function test_a_contact_must_have_a_valid_phone_and_the_permission_to_be_added(): void
    {
        $this->post($this->storeUrl(), ['FIRST_NAME' => 'No phone'])->assertSessionHasErrors('PHONE');
        $this->post($this->storeUrl(), ['PHONE' => 'not-a-number', 'FIRST_NAME' => 'Bad phone'])->assertSessionHasErrors('PHONE');
        $this->assertSame(0, Contacts::query()->count());

        $staff = $this->staffAt($this->workspace, $this->location($this->business));
        $this->authenticateAs($staff, ['view_contact']);
        $this->post($this->storeUrl(), ['PHONE' => '14155550161', 'FIRST_NAME' => 'Unpermitted'])->assertStatus(401);
        $this->assertSame(0, Contacts::query()->count());
    }

    public function test_the_contact_uid_is_stable_through_edits_status_changes_and_group_moves(): void
    {
        $this->post($this->storeUrl(), ['PHONE' => '14155550171', 'FIRST_NAME' => 'Stable'])->assertRedirect();
        $contact = Contacts::query()->where('phone', '14155550171')->sole();
        $uid = $contact->uid;
        $second = ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Second', 'status' => true]);

        $this->post(route('customer.workspaces.businesses.contact.update', [$this->workspace->uid, $this->business->uid, $this->group->uid]), [
            'contact_id' => $uid, 'PHONE' => '14155550171', 'FIRST_NAME' => 'Renamed',
        ])->assertSessionHas('status', 'success');
        $this->postJson(route('customer.workspaces.businesses.contact.status', [$this->workspace->uid, $this->business->uid, $this->group->uid]), ['id' => $uid])->assertJson(['status' => 'success']);
        $this->postJson(route('customer.workspaces.businesses.contact.batch_action', [$this->workspace->uid, $this->business->uid, $this->group->uid]), ['action' => 'move', 'ids' => [$uid], 'target_group' => $second->uid])->assertOk();

        $this->assertSame($uid, $contact->fresh()->uid);
        $this->assertSame((int) $second->id, (int) $contact->fresh()->group_id);
        $this->get($this->peopleUrl($this->workspace, $this->business, $uid))->assertOk()->assertSee('Renamed');
    }

    // ------------------------------------------------------------------ E

    /**
     * @param  list<array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private function fieldsPayload(ContactGroups $group, array $extra = []): array
    {
        $fields = $group->getFields->map(fn (ContactGroupFields $field) => [
            'uid' => $field->uid,
            'label' => $field->label,
            'tag' => $field->tag,
            'type' => $field->type,
            'required' => $field->required ? 1 : 0,
            'visible' => $field->visible ? 1 : 0,
            'default_value' => $field->default_value,
        ])->all();

        return ['fields' => array_merge($fields, $extra)];
    }

    private function fieldsUrl(ContactGroups $group, Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.contact.store-contact-field', [$workspace->uid, $business->uid, $group->uid]);
    }

    public function test_a_custom_field_is_added_filled_in_and_read_back_on_the_right_contact(): void
    {
        $this->post($this->fieldsUrl($this->group, $this->workspace, $this->business), $this->fieldsPayload($this->group, [
            ['uid' => '', 'label' => 'Roof type', 'tag' => 'roof_type', 'type' => 'text', 'required' => 0, 'visible' => 1],
        ]))->assertSessionHas('status', 'success');
        $this->assertSame(1, ContactGroupFields::query()->where('contact_group_id', $this->group->id)->where('tag', 'ROOF_TYPE')->count());

        $this->post($this->storeUrl(), ['PHONE' => '14155550181', 'FIRST_NAME' => 'Slate', 'ROOF_TYPE' => 'Slate'])->assertRedirect();
        $this->post($this->storeUrl(), ['PHONE' => '14155550182', 'FIRST_NAME' => 'Tile', 'ROOF_TYPE' => 'Clay tile'])->assertRedirect();
        $slate = Contacts::query()->where('phone', '14155550181')->sole();
        $tile = Contacts::query()->where('phone', '14155550182')->sole();

        $this->assertSame('Slate', $this->customField($slate, 'ROOF_TYPE'));
        $this->assertSame('Clay tile', $this->customField($tile, 'ROOF_TYPE'));
        $this->get($this->peopleUrl($this->workspace, $this->business, $slate->uid))->assertOk()->assertSee('Roof type')->assertSee('Slate')->assertDontSee('Clay tile');

        // Editing one person's value leaves the other's untouched.
        $this->post(route('customer.workspaces.businesses.contact.update', [$this->workspace->uid, $this->business->uid, $this->group->uid]), [
            'contact_id' => $slate->uid, 'PHONE' => '14155550181', 'ROOF_TYPE' => 'Metal',
        ])->assertSessionHas('status', 'success');
        $this->assertSame('Metal', $this->customField($slate->fresh(), 'ROOF_TYPE'));
        $this->assertSame('Clay tile', $this->customField($tile->fresh(), 'ROOF_TYPE'));
    }

    public function test_another_business_cannot_read_write_or_delete_this_businesss_custom_fields(): void
    {
        $roof = ContactGroupFields::create(['contact_group_id' => $this->group->id, 'label' => 'Roof type', 'type' => 'text', 'tag' => 'ROOF_TYPE', 'visible' => true, 'required' => false]);
        [$rivalOwner, $rivalBusiness, $rivalWorkspace, $rivalGroup] = $this->rival();
        $this->authenticateAs($rivalOwner);

        // Through my group's route, through theirs naming mine: 404, nothing changed.
        $this->post($this->fieldsUrl($this->group, $this->workspace, $this->business), $this->fieldsPayload($this->group))->assertNotFound();
        $this->post($this->fieldsUrl($this->group, $rivalWorkspace, $rivalBusiness), $this->fieldsPayload($this->group))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.contact.delete-contact-field', [$this->workspace->uid, $this->business->uid, $this->group->uid, $roof->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.contact.delete-contact-field', [$rivalWorkspace->uid, $rivalBusiness->uid, $rivalGroup->uid, $roof->uid]))->assertNotFound();

        $this->assertNotNull(ContactGroupFields::find($roof->id));
        $this->assertSame('Roof type', $roof->fresh()->label);
    }

    public function test_a_foreign_field_uid_or_group_id_smuggled_into_my_own_save_changes_nothing_of_theirs(): void
    {
        $roof = ContactGroupFields::create(['contact_group_id' => $this->group->id, 'label' => 'Roof type', 'type' => 'text', 'tag' => 'ROOF_TYPE', 'visible' => true, 'required' => false]);
        [$rivalOwner, $rivalBusiness, $rivalWorkspace, $rivalGroup] = $this->rival();
        $this->authenticateAs($rivalOwner);

        // The rival saves THEIR OWN list's fields, but names my field's uid and
        // tries to re-home it, and names my group for a brand-new field.
        $this->post($this->fieldsUrl($rivalGroup, $rivalWorkspace, $rivalBusiness), $this->fieldsPayload($rivalGroup, [
            ['uid' => $roof->uid, 'label' => 'Seized', 'tag' => 'SEIZED', 'type' => 'text', 'required' => 1, 'visible' => 1, 'contact_group_id' => $rivalGroup->id],
            ['uid' => '', 'label' => 'Planted', 'tag' => 'PLANTED', 'type' => 'text', 'required' => 0, 'visible' => 1, 'contact_group_id' => $this->group->id],
        ]));

        $fresh = $roof->fresh();
        $this->assertNotNull($fresh, 'a foreign field is never deleted by someone else\'s save');
        $this->assertSame('Roof type', $fresh->label);
        $this->assertSame('ROOF_TYPE', $fresh->tag);
        $this->assertSame((int) $this->group->id, (int) $fresh->contact_group_id, 'a foreign field is never re-homed');
        $this->assertFalse((bool) $fresh->required);
        $this->assertSame(0, ContactGroupFields::query()->where('contact_group_id', $this->group->id)->where('tag', 'PLANTED')->count(), 'nothing is planted in a foreign list');
    }

    public function test_a_stale_field_uid_is_not_resurrected_and_a_field_of_another_list_is_not_deletable_here(): void
    {
        $other = ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Other list', 'status' => true]);
        $elsewhere = ContactGroupFields::create(['contact_group_id' => $other->id, 'label' => 'Elsewhere', 'type' => 'text', 'tag' => 'ELSEWHERE', 'visible' => true, 'required' => false]);

        // A field of ANOTHER list of the same Business cannot be deleted through this one.
        $this->post(route('customer.workspaces.businesses.contact.delete-contact-field', [$this->workspace->uid, $this->business->uid, $this->group->uid, $elsewhere->uid]))->assertNotFound();
        $this->assertNotNull(ContactGroupFields::find($elsewhere->id));

        // Nor does naming it in this list's save take it over.
        $this->post($this->fieldsUrl($this->group, $this->workspace, $this->business), $this->fieldsPayload($this->group, [
            ['uid' => $elsewhere->uid, 'label' => 'Taken', 'tag' => 'TAKEN', 'type' => 'text', 'required' => 0, 'visible' => 1],
        ]));
        $this->assertSame('Elsewhere', $elsewhere->fresh()->label);
        $this->assertSame((int) $other->id, (int) $elsewhere->fresh()->contact_group_id);
    }
}
