<?php

namespace Tests\Feature\Crm;

use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Customer acceptance pass: one real owner, walking the whole Contacts -> CRM
 * -> Opportunity lead pipeline over HTTP exactly as the browser would drive
 * it, rather than fixture-seeding the middle of the flow the way the other
 * CRM/Contacts suites do (CreatesCrmFixtures::crmContact() creates the
 * Contact directly through Eloquent; this test is the one place that walks
 * the real "+ Add contact" -> "+ Add opportunity" journey end to end).
 *
 * Covers: a brand-new Business with no contact group yet, creating its first
 * list and its first contact, turning that contact into a CRM lead, moving
 * the lead through a pipeline stage, confirming the activity history, and a
 * second, unrelated Business being refused at every step of the same flow.
 */
class ContactsCrmOpportunityPipelineAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_owner_walks_contact_creation_through_opportunity_stage_change_and_history(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($customer);

        // 1. A brand-new Business has no contact group yet: "+ Add contact"
        // offers to create the first list instead of a group picker.
        $this->assertSame(0, ContactGroups::query()->where('business_id', $business->id)->count());

        $this->get(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('data-role="contacts-first-list"', false);

        // The first-list submit redirects back to "+ Add contact", which
        // now (exactly one group) redirects again, straight into that
        // group's own create-contact form.
        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]), ['next' => 'add'])
            ->assertRedirect(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]));

        $group = ContactGroups::query()->where('business_id', $business->id)->sole();
        $this->assertSame('Contacts', $group->name);

        $this->get(route('customer.workspaces.businesses.people.add', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]));

        // 2. Fill out and submit the real "add contact" form.
        $this->get(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]))
            ->assertOk();

        $storeResponse = $this->post(route('customer.workspaces.businesses.contact.store', [$workspace->uid, $business->uid, $group->uid]), [
            'PHONE' => '14155551234',
            'FIRST_NAME' => 'Jordan',
            'LAST_NAME' => 'Rivera',
        ]);

        $contact = Contacts::query()->where('group_id', $group->id)->sole();
        $this->assertEquals('14155551234', $contact->phone);

        // Contacts, person-first: lands on the new person's own profile, not
        // the group's legacy tabbed editor.
        $storeResponse->assertRedirect(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]));

        $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))
            ->assertOk()
            ->assertSee('Jordan');

        // 3. Set the CRM pipeline up (the real "Set up your pipeline" submit,
        // safe to run once) and turn the new contact into a lead.
        $this->post($this->crmRoute('setup', $workspace, $business))->assertRedirect();
        $pipeline = $this->standardPipeline($business);

        $this->get($this->crmRoute('opportunities.create', $workspace, $business, ['pipeline' => $pipeline->uid, 'contact' => $contact->uid]))
            ->assertOk()
            ->assertSee('value="' . $contact->uid . '" selected', false);

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), [
            'title' => 'Kitchen remodel',
            'contact' => $contact->uid,
            'pipeline' => $pipeline->uid,
            'value' => '2500',
        ])->assertRedirect($this->crmRoute('board', $workspace, $business, ['pipeline' => $pipeline->uid]));

        $opportunity = CrmOpportunity::query()->where('business_id', $business->id)->sole();
        $this->assertSame($contact->id, $opportunity->contact_id);
        $this->assertSame('new_inquiry', $opportunity->stage->semantic_key);

        // 4. Move the lead to the next stage.
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$opportunity->uid]), ['stage' => $qualified->uid])
            ->assertOk()
            ->assertJson(['moved' => true, 'stage' => ['uid' => $qualified->uid, 'name' => 'Qualified']]);
        $this->assertSame($qualified->id, $opportunity->fresh()->stage_id);

        // 5. Activity/history reflects both the creation and the stage change.
        $html = $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$opportunity->uid]))->assertOk()->getContent();
        preg_match_all('/data-event="([a-z_]+)"/', $html, $events);
        $this->assertSame(['stage_changed', 'created'], $events[1]);
        $this->assertStringContainsString('Moved from <strong>New inquiry</strong> to <strong>Qualified</strong>', $html);

        // 6. A second, unrelated Business is refused at every step of the
        // same flow: the group, the contact's profile, the board and the
        // opportunity all answer the same 404 as an unknown uid, and the
        // foreign attempt to move the deal leaves it untouched.
        [$otherCustomer] = $this->crmTenant('Rival Studio', 'Rival');
        $this->authenticateAs($otherCustomer);

        $this->get(route('customer.workspaces.businesses.contact.create', [$workspace->uid, $business->uid, $group->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.contact.store', [$workspace->uid, $business->uid, $group->uid]), [
            'PHONE' => '14155559999',
            'FIRST_NAME' => 'Intruder',
        ])->assertNotFound();
        $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))->assertNotFound();
        $this->get($this->crmRoute('board', $workspace, $business))->assertNotFound();
        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$opportunity->uid]))->assertNotFound();
        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$opportunity->uid]), ['stage' => $this->stageKeyed($pipeline, 'proposal_sent')->uid])
            ->assertNotFound();

        $this->assertSame($qualified->id, $opportunity->fresh()->stage_id, 'The foreign move attempt must leave the deal untouched.');
        $this->assertSame(1, Contacts::query()->where('group_id', $group->id)->count(), 'The foreign store attempt must not have created a contact.');
    }

    /**
     * The other half of "creating/importing a contact": a real owner who
     * pastes a short phone list into "Import contacts" instead of using the
     * single-contact form, and then finds that imported lead in the CRM
     * "Add opportunity" contact picker exactly like a manually-added one.
     */
    public function test_owner_imports_contacts_and_an_imported_contact_is_usable_as_a_crm_lead(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.people.first-list', [$workspace->uid, $business->uid]), ['next' => 'import'])
            ->assertRedirect(route('customer.workspaces.businesses.people.import', [$workspace->uid, $business->uid]));

        $group = ContactGroups::query()->where('business_id', $business->id)->sole();

        $this->get(route('customer.workspaces.businesses.people.import', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.contact.import', [$workspace->uid, $business->uid, $group->uid]));

        $this->get(route('customer.workspaces.businesses.contact.import', [$workspace->uid, $business->uid, $group->uid]))
            ->assertOk();

        // The paste-text import ("Add contact" -> import tab): a real US
        // phone number per line, comma-separated is also supported but
        // new_line matches what the import form's textarea produces.
        $importUrl = "workspaces/{$workspace->uid}/businesses/{$business->uid}/contacts/{$group->uid}/import";
        $this->post($importUrl, [
            'recipients' => "14155552671\n14155552672",
            'delimiter' => 'new_line',
        ])->assertRedirect(route('customer.workspaces.businesses.contacts.show', [$workspace->uid, $business->uid, $group->uid]));

        $imported = Contacts::query()->where('group_id', $group->id)->orderBy('id')->get();
        $this->assertSame(['14155552671', '14155552672'], $imported->pluck('phone')->map(fn ($phone) => (string) $phone)->all());

        // The imported contact is a real Business contact: usable in the
        // CRM "Add opportunity" picker exactly like a manually-added one.
        $this->post($this->crmRoute('setup', $workspace, $business))->assertRedirect();
        $pipeline = $this->standardPipeline($business);
        $lead = $imported->first();

        $results = $this->getJson($this->crmRoute('contacts.search', $workspace, $business, ['q' => '2671']))->assertOk()->json('results');
        $this->assertSame([$lead->uid], array_column($results, 'id'));

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), [
            'title' => 'Imported lead deal',
            'contact' => $lead->uid,
            'pipeline' => $pipeline->uid,
        ])->assertRedirect($this->crmRoute('board', $workspace, $business, ['pipeline' => $pipeline->uid]));

        $deal = CrmOpportunity::query()->where('business_id', $business->id)->sole();
        $this->assertSame($lead->id, $deal->contact_id);
    }
}
