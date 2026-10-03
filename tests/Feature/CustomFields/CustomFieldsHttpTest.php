<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Settings -> Custom fields and the Contact-details section: the HTTP surface —
 * tenancy, permissions, typed controls, validation and the Contact Location ACL.
 */
class CustomFieldsHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function manager(): CustomFieldDefinitionManager
    {
        return app(CustomFieldDefinitionManager::class);
    }

    private function args(Workspace $workspace, Business $business, array $extra = []): array
    {
        return [$workspace->uid, $business->uid, ...$extra];
    }

    private function staffUser(Workspace $workspace, Business $business, WorkspaceBusinessAccessScope $businessScope = WorkspaceBusinessAccessScope::All, LocationAccessScope $locationScope = LocationAccessScope::All): array
    {
        // A real Customer account (the shell reads its subscription), joined to
        // the Workspace as Staff with the asked-for Business / Location reach.
        $customer = $this->createCustomer();

        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => $businessScope,
            'location_access_scope' => $locationScope,
        ]);

        if ($businessScope === WorkspaceBusinessAccessScope::Selected) {
            $this->assign($membership, $business);
        }

        return [$customer->user, $membership];
    }

    private function signInStaff(User $user, array $permissions): void
    {
        $user->email_verified_at = now();
        $user->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

    private function location(Business $business): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    // =================================================================
    // Settings -> Custom fields
    // =================================================================

    public function test_the_owner_creates_edits_archives_and_restores_a_field_and_the_key_never_changes(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'Event Date', 'type' => 'date'])
            ->assertRedirect();
        $field = CustomFieldDefinition::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('event_date', $field->key);

        $page = $this->get(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspace, $business)))->assertOk();
        $page->assertSee('Event Date');
        $page->assertSee('{{contact.event_date}}', false);
        $page->assertSee('Date');
        $page->assertSee('Active');

        $this->post(route('customer.workspaces.businesses.custom-fields.update', $this->args($workspace, $business, [$field->uid])), ['label' => 'Wedding Day'])->assertRedirect();
        $field->refresh();
        $this->assertSame('Wedding Day', $field->label);
        $this->assertSame('event_date', $field->key);

        $this->post(route('customer.workspaces.businesses.custom-fields.archive', $this->args($workspace, $business, [$field->uid])))->assertRedirect();
        $this->assertTrue($field->fresh()->isArchived());
        $this->get(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspace, $business)))->assertSee('Archived');

        $this->post(route('customer.workspaces.businesses.custom-fields.restore', $this->args($workspace, $business, [$field->uid])))->assertRedirect();
        $this->assertFalse($field->fresh()->isArchived());
    }

    public function test_options_are_edited_by_stable_id_and_fields_can_be_moved(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'Event Type', 'type' => 'select', 'options' => "Wedding\nCorporate"])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'Venue', 'type' => 'text'])->assertRedirect();
        $kind = CustomFieldDefinition::query()->where('key', 'event_type')->firstOrFail();
        [$wedding, $corporate] = $kind->optionList();

        $this->post(route('customer.workspaces.businesses.custom-fields.update', $this->args($workspace, $business, [$kind->uid])), [
            'label' => 'Event Type',
            'options' => [['id' => $wedding['id'], 'label' => 'Wedding reception'], ['id' => $corporate['id'], 'label' => '']],
            'new_options' => "Birthday\nGala",
        ])->assertRedirect();

        $options = $kind->fresh()->optionList();
        $this->assertSame(['Wedding reception', 'Birthday', 'Gala'], array_column($options, 'label'));
        $this->assertSame($wedding['id'], $options[0]['id'], 'A renamed option keeps its stable id.');

        $venue = CustomFieldDefinition::query()->where('key', 'venue')->firstOrFail();
        $this->post(route('customer.workspaces.businesses.custom-fields.move', $this->args($workspace, $business, [$venue->uid])), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Venue', 'Event Type'], $this->manager()->forBusiness($business)->pluck('label')->all());
    }

    public function test_a_refused_change_comes_back_as_a_message_and_writes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);
        $this->manager()->create($business, 'Venue', 'text');

        $this->from(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspace, $business)))
            ->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'venue', 'type' => 'text'])
            ->assertSessionHasErrors('custom_field');
        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'X', 'type' => 'telepathy'])
            ->assertSessionHasErrors('type');
        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'Pick', 'type' => 'select', 'options' => ''])
            ->assertSessionHasErrors('custom_field');

        $this->assertSame(1, CustomFieldDefinition::query()->count());
    }

    public function test_another_businesses_field_is_a_404_and_a_foreign_business_is_unreachable(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->crmTenant('Business B', 'Workspace B');
        $fieldB = $this->manager()->create($businessB, 'Secret', 'text');
        $this->authenticateAs($customerA);

        foreach (['update' => ['label' => 'Hijack'], 'archive' => [], 'restore' => [], 'move' => ['direction' => 'up']] as $action => $payload) {
            $this->post(route('customer.workspaces.businesses.custom-fields.' . $action, $this->args($workspaceA, $businessA, [$fieldB->uid])), $payload)->assertNotFound();
        }

        $this->get(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspaceB, $businessB)))->assertNotFound();
        $this->assertFalse($fieldB->fresh()->isArchived());
        $this->assertSame('Secret', $fieldB->fresh()->label);

        $this->get(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspaceA, $businessA)))->assertOk()->assertDontSee('Secret');
    }

    public function test_view_only_staff_see_the_list_but_cannot_change_it(): void
    {
        [, $business, $workspace] = $this->crmTenant();
        $field = $this->manager()->create($business, 'Venue', 'text');
        [$staff] = $this->staffUser($workspace, $business);
        $this->signInStaff($staff, ['view_contact']);

        $page = $this->get(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspace, $business)))->assertOk();
        $page->assertSee('Venue');
        $page->assertDontSee('data-role="custom-field-add"', false);

        $this->post(route('customer.workspaces.businesses.custom-fields.store', $this->args($workspace, $business)), ['label' => 'New', 'type' => 'text'])->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.custom-fields.archive', $this->args($workspace, $business, [$field->uid])))->assertStatus(401);
        $this->assertFalse($field->fresh()->isArchived());
    }

    // =================================================================
    // Contact details
    // =================================================================

    public function test_the_contact_page_shows_typed_controls_and_saves_values(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);
        $contact = $this->crmContact($business, ['FIRST_NAME' => 'Pat'], '14155550123');
        $manager = $this->manager();
        $date = $manager->create($business, 'Event Date', 'date');
        $guests = $manager->create($business, 'Guest Count', 'number');
        $kind = $manager->create($business, 'Event Type', 'select', ['Wedding', 'Corporate']);
        $insured = $manager->create($business, 'Insured', 'boolean');
        $extras = $manager->create($business, 'Extras', 'multi_select', ['Props', 'Backdrop']);
        $show = route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$contact->uid]));

        $html = $this->get($show)->assertOk()->getContent();
        $this->assertStringContainsString('data-role="contact-custom-fields"', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('<option value="yes"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('>Wedding</option>', $html, 'Options show their labels.');

        $this->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$contact->uid])), ['custom_fields' => [
            $date->uid => '2027-06-14',
            $guests->uid => '180',
            $kind->uid => $kind->optionList()[1]['id'],
            $insured->uid => 'yes',
            $extras->uid => ['', $extras->optionList()[0]['id']],
        ]])->assertRedirect($show);

        $page = $this->get($show)->assertOk();
        $page->assertSee('value="2027-06-14"', false);
        $page->assertSee('value="180"', false);
        $entries = app(CustomFieldValueService::class)->valuesFor($business, $contact->fresh());
        $this->assertCount(5, $entries);
        $this->assertTrue($entries->firstWhere(fn ($e) => $e['definition']->id === $insured->id)['value']);

        // Clearing a field removes just that value.
        $this->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$contact->uid])), ['custom_fields' => [$date->uid => '']])->assertRedirect($show);
        $this->assertCount(4, app(CustomFieldValueService::class)->valuesFor($business, $contact->fresh()));
    }

    public function test_an_invalid_value_is_refused_and_nothing_is_saved(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);
        $contact = $this->crmContact($business);
        $guests = $this->manager()->create($business, 'Guest Count', 'number');
        $venue = $this->manager()->create($business, 'Venue', 'text');
        $show = route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$contact->uid]));

        $this->from($show)->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$contact->uid])), ['custom_fields' => [
            $venue->uid => 'Grand Hotel', $guests->uid => 'plenty',
        ]])->assertSessionHasErrors('custom_fields');

        $this->assertSame(0, CustomFieldValue::query()->count());
    }

    public function test_a_foreign_field_uid_in_the_request_fails_closed(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Business A', 'Workspace A');
        [, $other] = $this->crmTenant('Business B', 'Workspace B');
        $this->authenticateAs($customer);
        $contact = $this->crmContact($business);
        $foreign = $this->manager()->create($other, 'Secret', 'text');
        $mine = $this->manager()->create($business, 'Venue', 'text');

        $this->from(route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$contact->uid])))
            ->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$contact->uid])), ['custom_fields' => [
                $mine->uid => 'Fine', $foreign->uid => 'Leak',
            ]])->assertSessionHasErrors('custom_fields');

        $this->assertSame(0, CustomFieldValue::query()->count(), 'Nothing is written, not even the valid field.');
    }

    public function test_contact_location_acl_hides_and_blocks_values_for_an_ungranted_location(): void
    {
        [, $business, $workspace] = $this->crmTenant();
        $granted = $this->location($business);
        $denied = $this->location($business);
        $field = $this->manager()->create($business, 'Venue', 'text');
        $values = app(CustomFieldValueService::class);

        $deniedContact = $this->crmContact($business, ['FIRST_NAME' => 'Dee'], '14155550001');
        $deniedContact->forceFill(['location_id' => $denied->id])->save();
        $values->set($business, $deniedContact->fresh(), $field, 'Secret Hall');
        $grantedContact = $this->crmContact($business, ['FIRST_NAME' => 'Gus'], '14155550002');
        $grantedContact->forceFill(['location_id' => $granted->id])->save();

        [$staff, $membership] = $this->staffUser($workspace, $business, WorkspaceBusinessAccessScope::Selected, LocationAccessScope::Selected);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);
        $this->signInStaff($staff, ['view_contact', 'update_contact']);

        $deniedPage = $this->get(route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$deniedContact->uid])))->assertOk();
        $deniedPage->assertDontSee('data-role="contact-custom-fields"', false);
        $deniedPage->assertDontSee('Secret Hall');

        $this->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$deniedContact->uid])), ['custom_fields' => [$field->uid => 'Overwritten']])
            ->assertNotFound();
        $this->assertSame('Secret Hall', $values->valuesFor($business, $deniedContact->fresh())->first()['value']);

        $this->get(route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$grantedContact->uid])))
            ->assertOk()->assertSee('data-role="contact-custom-fields"', false);
        $this->post(route('customer.workspaces.businesses.people.custom-fields.update', $this->args($workspace, $business, [$grantedContact->uid])), ['custom_fields' => [$field->uid => 'City Hall']])
            ->assertRedirect();
        $this->assertSame('City Hall', $values->valuesFor($business, $grantedContact->fresh())->first()['value']);
    }

    public function test_an_archived_field_with_a_value_is_shown_read_only_and_an_empty_one_is_hidden(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);
        $contact = $this->crmContact($business);
        $kept = $this->manager()->create($business, 'Venue', 'text');
        $empty = $this->manager()->create($business, 'Old Notes', 'text');
        app(CustomFieldValueService::class)->set($business, $contact, $kept, 'Grand Hotel');
        $this->manager()->archive($business, $kept);
        $this->manager()->archive($business, $empty);

        $page = $this->get(route('customer.workspaces.businesses.people.show', $this->args($workspace, $business, [$contact->uid])))->assertOk();

        $page->assertSee('Grand Hotel');
        $page->assertSee('(archived)');
        $page->assertDontSee('Old Notes');
        $page->assertDontSee('name="custom_fields[' . $kept->uid . ']"', false);
    }

    public function test_settings_navigation_offers_custom_fields_to_people_who_can_see_contacts(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.settings.show', $this->args($workspace, $business)))
            ->assertOk()
            ->assertSee('Custom fields')
            ->assertSee(route('customer.workspaces.businesses.custom-fields.index', $this->args($workspace, $business)), false);
    }
}
