<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\Contacts\ContactEmailIdentity;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The New-contact modal's save endpoint, and the built-in canonical EMAIL identity every
 * surface (profile, table, Conversations, manual send, Automations) reads.
 */
class ContactNewModalAndEmailIdentityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function storeUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.people.store', [$workspace->uid, $business->uid]);
    }

    private function group(Business $business, string $name = 'Leads & Clients', bool $withDefaults = true): ContactGroups
    {
        // The contact flow always lands at a Location: one active Location is used automatically.
        if (! \App\Models\BusinessLocation::query()->where('business_id', $business->id)->exists()) {
            \App\Models\BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        }

        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true]);

        if (! $withDefaults) {
            // Simulate a legacy group created before EMAIL was a built-in field.
            $group->contactGroupFields()->whereIn('tag', ['EMAIL'])->delete();
        }

        return $group;
    }

    private function save(Workspace $workspace, Business $business, array $payload)
    {
        return $this->postJson($this->storeUrl($workspace, $business), $payload);
    }

    public function test_new_groups_get_the_built_in_email_field(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);

        $this->assertTrue($group->contactGroupFields()->where('tag', 'EMAIL')->exists());
    }

    public function test_saving_with_us_country_stores_canonical_phone_and_email_and_shows_in_the_table_and_profile(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);

        $response = $this->save($workspace, $business, [
            'country' => 'US', 'phone' => '(720) 664-0969', 'first_name' => 'Amira', 'last_name' => 'Misurati',
            'email' => '  Amira@Example.COM ', 'group' => $group->uid, 'notes' => 'Wedding June 14',
        ]);
        $response->assertOk()->assertJsonPath('status', 'success');

        $contact = Contacts::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('17206640969', (string) $contact->phone);
        $this->assertSame($contact->uid, $response->json('contact.uid'));

        // The email is on the canonical EMAIL-tagged field, normalized, and the resolver finds it.
        $this->assertSame('amira@example.com', app(BusinessEmailContactResolver::class)->singleAddressFor($contact));
        $this->assertSame(1, $group->contactGroupFields()->where('tag', 'EMAIL')->count(), 'No second email field is invented.');

        $list = $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]))->assertOk();
        $list->assertSee('Amira Misurati')->assertSee('amira@example.com')->assertSee('+1 (720) 664-0969');

        $profile = $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))->assertOk();
        $profile->assertSee('amira@example.com')->assertSee('Wedding June 14')->assertSee('Notes');
    }

    public function test_saving_with_lithuania_uses_the_selected_country_not_a_us_assumption(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);

        $this->save($workspace, $business, ['country' => 'LT', 'phone' => '612 34567', 'group' => $group->uid])
            ->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('37061234567', (string) Contacts::query()->where('business_id', $business->id)->value('phone'));
    }

    public function test_invalid_numbers_return_inline_errors_and_create_nothing(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);

        $this->save($workspace, $business, ['country' => 'US', 'phone' => '720 664', 'group' => $group->uid])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'too short'));
        $this->save($workspace, $business, ['country' => 'LT', 'phone' => '(720) 664-0969', 'group' => $group->uid])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
        $this->save($workspace, $business, ['country' => 'US', 'phone' => '(720) 664-0969', 'email' => 'not-an-email', 'group' => $group->uid])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);

        $this->assertSame(0, Contacts::query()->where('business_id', $business->id)->count());
    }

    public function test_double_submission_creates_one_contact(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner);
        $payload = ['country' => 'US', 'phone' => '(720) 664-0969', 'group' => $group->uid];

        $this->save($workspace, $business, $payload)->assertOk();
        $this->save($workspace, $business, $payload)->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);

        $this->assertSame(1, Contacts::query()->where('business_id', $business->id)->count());
    }

    public function test_a_group_of_another_business_is_refused(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $otherBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');
        $this->group($business);
        $foreign = $this->group($otherBusiness, 'Foreign');
        $this->authenticateAs($owner);

        $this->save($workspace, $business, ['country' => 'US', 'phone' => '(720) 664-0969', 'group' => $foreign->uid])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['group']]);
        $this->assertSame(0, Contacts::query()->count());
    }

    public function test_without_create_permission_nothing_is_created(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $this->authenticateAs($owner, ['view_contact']);

        $this->save($workspace, $business, ['country' => 'US', 'phone' => '(720) 664-0969', 'group' => $group->uid])
            ->assertStatus(401);
        $this->assertSame(0, Contacts::query()->count());
    }

    public function test_the_modal_options_endpoint_requires_the_same_permission_and_lists_only_this_business(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $otherBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');
        $this->group($business, 'Mine');
        $this->group($otherBusiness, 'Theirs');
        $this->authenticateAs($owner);

        $json = $this->getJson(route('customer.workspaces.businesses.people.new-contact-options', [$workspace->uid, $business->uid]))->assertOk()->json();
        $this->assertSame(['Mine'], array_column($json['groups'], 'name'));
    }

    public function test_the_contacts_page_opens_the_modal_not_the_old_form_and_hides_it_without_permission(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->group($business);
        $this->authenticateAs($owner);
        $url = route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]);

        $this->get($url)->assertOk()->assertSee('id="mgx-new-contact"', false)->assertSee('data-mgx-open-contact', false)
            ->assertSee('Only the phone number is required.')->assertSee('Save contact')->assertSee('Add another after saving');

        $this->authenticateAs($owner, ['view_contact', 'view_contact_group']);
        $this->get($url)->assertOk()->assertDontSee('id="mgx-new-contact"', false);
    }

    public function test_existing_group_without_email_field_gets_it_on_demand_without_touching_other_data(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Legacy', false);
        $custom = ContactGroupFields::create(['contact_group_id' => $group->id, 'tag' => 'EMAIL_ADDRESS', 'label' => 'Email', 'type' => 'text', 'visible' => true, 'required' => false]);
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '12025550101', 'status' => Contacts::STATUS_SUBSCRIBE]);
        ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $custom->id, 'value' => 'custom@example.com']);
        $this->authenticateAs($owner);

        $identity = app(ContactEmailIdentity::class);
        $this->assertSame(['state' => 'none', 'email' => null], $identity->status($contact), 'An arbitrary custom "Email" field is never authoritative.');

        $this->post(route('customer.workspaces.businesses.people.email.update', [$workspace->uid, $business->uid, $contact->uid]), ['email' => 'Real@Example.com'])
            ->assertRedirect();

        $this->assertSame(['state' => 'valid', 'email' => 'real@example.com'], $identity->status($contact->fresh()));
        $this->assertSame('custom@example.com', ContactsCustomField::where('field_id', $custom->id)->value('value'), 'The custom field value is untouched.');
        $this->assertSame(1, ContactGroupFields::where('contact_group_id', $group->id)->where('tag', 'EMAIL')->count());

        // Saving again does not create a second field.
        $identity->save($contact->fresh(), 'again@example.com');
        $this->assertSame(1, ContactGroupFields::where('contact_group_id', $group->id)->where('tag', 'EMAIL')->count());
        $this->assertSame('again@example.com', app(BusinessEmailContactResolver::class)->singleAddressFor($contact->fresh()));
    }

    public function test_ambiguous_and_invalid_emails_are_never_guessed(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business);
        $emailFieldId = $group->contactGroupFields()->where('tag', 'EMAIL')->value('id');
        $second = ContactGroupFields::create(['contact_group_id' => $group->id, 'tag' => 'EMAIL', 'label' => 'Email 2', 'type' => 'text', 'visible' => true, 'required' => false]);
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '12025550102', 'status' => Contacts::STATUS_SUBSCRIBE]);
        ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $emailFieldId, 'value' => 'a@example.com']);
        ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $second->id, 'value' => 'b@example.com']);

        $identity = app(ContactEmailIdentity::class);
        $this->assertSame('ambiguous', $identity->status($contact)['state']);
        $this->assertNull(app(BusinessEmailContactResolver::class)->singleAddressFor($contact));

        $this->expectException(\InvalidArgumentException::class);
        $identity->save($contact, 'nope');
    }

    public function test_cross_business_contact_email_cannot_be_edited(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $otherBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');
        $foreign = Contacts::create(['customer_id' => $otherBusiness->customer_id, 'business_id' => $otherBusiness->id, 'group_id' => $this->group($otherBusiness)->id, 'phone' => '12025550103', 'status' => Contacts::STATUS_SUBSCRIBE]);
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.businesses.people.email.update', [$workspace->uid, $business->uid, $foreign->uid]), ['email' => 'x@example.com'])
            ->assertNotFound();
        $this->assertSame(0, DB::table('contacts_custom_field')->where('contact_id', $foreign->id)->count());
    }

    public function test_the_edit_form_of_a_legacy_group_exposes_the_built_in_email_field(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Legacy', false);
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '12025550104', 'status' => Contacts::STATUS_SUBSCRIBE]);
        $this->authenticateAs($owner);
        $this->assertFalse($group->contactGroupFields()->where('tag', 'EMAIL')->exists());

        $url = route('customer.workspaces.businesses.contact.edit', [$workspace->uid, $business->uid, $group->uid]) . '?contact_id=' . $contact->uid;
        $this->get($url)->assertOk()->assertSee('name="EMAIL"', false);

        $this->assertSame(1, $group->contactGroupFields()->where('tag', 'EMAIL')->count());
        $this->get($url)->assertOk();
        $this->assertSame(1, $group->contactGroupFields()->where('tag', 'EMAIL')->count(), 'Opening Edit twice does not create a second field.');
    }
}
