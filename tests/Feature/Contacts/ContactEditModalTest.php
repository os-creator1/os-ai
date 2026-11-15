<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The Edit-contact modal's three endpoints (details, update, delete): canonical writers only, the contact keeps its uid, and
 * Business / permission boundaries answer exactly like the rest of Contacts.
 */
class ContactEditModalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function route(string $name, Workspace $workspace, Business $business, string $uid): string
    {
        return route('customer.workspaces.businesses.people.' . $name, [$workspace->uid, $business->uid, $uid]);
    }

    private function group(Business $business, string $name = 'Leads & Clients'): ContactGroups
    {
        if (! BusinessLocation::query()->where('business_id', $business->id)->exists()) {
            BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        }

        return ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);
    }

    /** A contact made the normal way (the New-contact modal's save), so every stored value is canonical. */
    private function person(Workspace $workspace, Business $business, ContactGroups $group, array $extra = []): Contacts
    {
        $response = $this->postJson(route('customer.workspaces.businesses.people.store', [$workspace->uid, $business->uid]), $extra + [
            'country' => 'US', 'phone' => '(601) 692-6705', 'first_name' => 'John', 'last_name' => 'Sheppard',
            'email' => 'john@example.com', 'group' => $group->uid, 'notes' => 'Met at expo',
        ])->assertOk();

        return Contacts::query()->where('uid', $response->json('contact.uid'))->firstOrFail();
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'country' => 'US', 'phone' => '(601) 692-6705', 'first_name' => 'John', 'last_name' => 'Sheppard',
            'email' => 'john@example.com', 'company' => '', 'notes' => 'Met at expo',
        ];
    }

    public function test_details_return_the_stored_values_with_the_phone_split_into_country_and_national_number(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group, ['country' => 'LT', 'phone' => '612 34567']);

        $json = $this->getJson($this->route('edit', $workspace, $business, $contact->uid))->assertOk()->json();

        $this->assertSame('LT', $json['country']);
        $this->assertStringContainsString('612', $json['phone']);
        $this->assertSame('John', $json['first_name']);
        $this->assertSame('Sheppard', $json['last_name']);
        $this->assertSame('john@example.com', $json['email']);
        $this->assertSame('Met at expo', $json['notes']);
        $this->assertSame($group->uid, $json['group']);
        $this->assertSame(['Leads & Clients'], array_column($json['groups'], 'name'));
        $this->assertTrue($json['can_delete']);
        $this->assertSame($contact->uid, $json['uid']);
    }

    public function test_update_writes_through_the_canonical_fields_and_keeps_the_contact_identity(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload([
            'phone' => '(720) 664-0969', 'first_name' => 'Jon', 'last_name' => 'Sheppard-Lee',
            'email' => '  Jon@Example.COM ', 'company' => 'Acme Events', 'notes' => 'Wedding June 14',
        ]))->assertOk()->assertJsonPath('status', 'success');

        $fresh = Contacts::query()->findOrFail($contact->id);
        $this->assertSame($contact->uid, $fresh->uid);
        $this->assertSame((int) $contact->business_id, (int) $fresh->business_id);
        $this->assertSame($contact->location_id, $fresh->location_id);
        $this->assertSame('17206640969', (string) $fresh->phone);
        $this->assertSame('Jon', $fresh->getValueByTag('FIRST_NAME'));
        $this->assertSame('Sheppard-Lee', $fresh->getValueByTag('LAST_NAME'));
        $this->assertSame('jon@example.com', $fresh->getValueByTag('EMAIL'));
        $this->assertSame('Acme Events', $fresh->getValueByTag('COMPANY'));
        $this->assertSame('Wedding June 14', $fresh->getValueByTag('NOTES'));
        $this->assertSame(1, $group->contactGroupFields()->where('tag', 'EMAIL')->count(), 'no second email field is invented');
        $this->assertSame(1, Contacts::query()->where('business_id', $business->id)->count(), 'an edit never creates a contact');

        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]))
            ->assertOk()->assertSee('Jon Sheppard-Lee')->assertSee('jon@example.com')->assertSee('Acme Events')->assertSee('+1 (720) 664-0969');
    }

    public function test_optional_fields_can_be_cleared(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['email' => '', 'notes' => '', 'first_name' => '', 'last_name' => '']))
            ->assertOk();

        $fresh = Contacts::query()->findOrFail($contact->id);
        $this->assertSame('', trim((string) $fresh->getValueByTag('EMAIL')));
        $this->assertSame('', trim((string) $fresh->getValueByTag('NOTES')));
        $this->assertSame('', trim((string) $fresh->getValueByTag('FIRST_NAME')));
    }

    public function test_invalid_input_returns_inline_errors_and_changes_nothing(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['phone' => '720 664', 'first_name' => 'Changed']))
            ->assertStatus(422)->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'too short'));
        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['email' => 'not-an-email', 'first_name' => 'Changed']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['group' => 'nope', 'first_name' => 'Changed']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['group']]);

        $fresh = Contacts::query()->findOrFail($contact->id);
        $this->assertSame('16016926705', (string) $fresh->phone);
        $this->assertSame('John', $fresh->getValueByTag('FIRST_NAME'));
    }

    public function test_a_number_already_held_by_another_contact_is_refused(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $first = $this->person($workspace, $business, $group);
        $second = $this->person($workspace, $business, $group, ['phone' => '(720) 664-0969', 'email' => 'second@example.com']);

        $this->postJson($this->route('update', $workspace, $business, $second->uid), $this->payload(['phone' => '(601) 692-6705']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
        $this->assertSame('17206640969', (string) Contacts::query()->findOrFail($second->id)->phone);

        // Saving a contact with its own, unchanged number is not a duplicate.
        $this->postJson($this->route('update', $workspace, $business, $first->uid), $this->payload(['first_name' => 'Johnny']))->assertOk();
    }

    public function test_a_contact_of_another_business_is_not_found_for_every_endpoint(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [$otherOwner, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');
        $this->group($business);
        $this->authenticateAs($otherOwner);
        $foreign = $this->person($otherWorkspace, $otherBusiness, $this->group($otherBusiness));
        $this->authenticateAs($owner);

        $this->getJson($this->route('edit', $workspace, $business, $foreign->uid))->assertNotFound();
        $this->postJson($this->route('update', $workspace, $business, $foreign->uid), $this->payload(['first_name' => 'Hacked']))->assertNotFound();
        $this->postJson($this->route('destroy', $workspace, $business, $foreign->uid))->assertNotFound();

        $this->assertNotNull(Contacts::query()->find($foreign->id));
        $this->assertSame('John', Contacts::query()->findOrFail($foreign->id)->getValueByTag('FIRST_NAME'));
    }

    public function test_without_the_update_permission_nothing_is_changed_and_no_edit_control_is_rendered(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->authenticateAs($owner, ['view_contact']);
        $this->getJson($this->route('edit', $workspace, $business, $contact->uid))->assertStatus(401);
        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['first_name' => 'Nope']))->assertStatus(401);
        $this->assertSame('John', Contacts::query()->findOrFail($contact->id)->getValueByTag('FIRST_NAME'));

        $page = $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]))->assertOk();
        $page->assertDontSee('data-mgx-edit-contact', false)->assertDontSee('id="mgx-edit-contact"', false);
    }

    public function test_the_list_and_profile_render_the_modal_for_someone_who_may_edit(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]))->assertOk()
            ->assertSee('id="mgx-edit-contact"', false)->assertSee('data-mgx-edit-contact', false)->assertSee('data-uid="' . $contact->uid . '"', false)
            ->assertSee('Save changes')->assertSee('Delete contact');
        $this->get($this->route('show', $workspace, $business, $contact->uid))->assertOk()
            ->assertSee('id="mgx-edit-contact"', false)->assertSee('data-role="contact-edit"', false);
    }

    public function test_moving_group_carries_values_across_and_refuses_a_move_that_would_lose_a_custom_field(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $from = $this->group($business, 'Leads');
        $to = $this->group($business, 'Vendors');
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $from);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['group' => $to->uid, 'company' => 'Acme']))
            ->assertOk();

        $fresh = Contacts::query()->findOrFail($contact->id);
        $this->assertSame((int) $to->id, (int) $fresh->group_id);
        $this->assertSame('john@example.com', $fresh->getValueByTag('EMAIL'));
        $this->assertSame('Met at expo', $fresh->getValueByTag('NOTES'));
        $this->assertSame('Acme', $fresh->getValueByTag('COMPANY'));
        $this->assertSame(0, DB::table('contacts_custom_field as v')->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)->where('f.contact_group_id', $from->id)->count(), 'no value is left pointing at the old group');

        // A custom field with a value that the target has no field for blocks the move; nothing changes.
        $custom = $to->contactGroupFields()->create(['contact_group_id' => $to->id, 'type' => 'text', 'label' => 'Shoe size', 'tag' => 'SHOE_SIZE', 'required' => false, 'visible' => true]);
        DB::table('contacts_custom_field')->insert(['contact_id' => $contact->id, 'field_id' => $custom->id, 'value' => '42', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson($this->route('update', $workspace, $business, $contact->uid), $this->payload(['group' => $from->uid]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['group']]);
        $this->assertSame((int) $to->id, (int) Contacts::query()->findOrFail($contact->id)->group_id);
    }

    public function test_delete_removes_the_contact_behind_its_own_permission(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $contact = $this->person($workspace, $business, $group);

        $this->authenticateAs($owner, ['view_contact', 'update_contact']);
        $this->postJson($this->route('destroy', $workspace, $business, $contact->uid))->assertStatus(401);
        $this->assertNotNull(Contacts::query()->find($contact->id));

        $this->authenticateAs($owner);
        $this->postJson($this->route('destroy', $workspace, $business, $contact->uid))
            ->assertOk()->assertJsonPath('status', 'success')
            ->assertJsonPath('redirect', route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]));
        $this->assertNull(Contacts::query()->find($contact->id));
        $this->postJson($this->route('destroy', $workspace, $business, $contact->uid))->assertNotFound();
    }
}
