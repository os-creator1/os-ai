<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2 — the contact picker behind the builder's "Test workflow".
 *
 * WHY IT EXISTS. Test workflow simulates a real contact's path, and the
 * simulate endpoint takes that contact's uid. The builder used to ask a person
 * to TYPE the uid into a browser prompt; this endpoint lets them pick by name or
 * number instead. It is ContactDirectory's own Business-scoped search, read-only.
 *
 * What is proven here, beyond the authorization matrix every V2-E route is
 * already held to (WorkflowHttpAuthorizationTest, via the route inventory):
 * only this Business's contacts, only name/phone/uid, search narrows, seeing
 * contacts is its own permission, and a picked contact really simulates.
 */
class WorkflowTestContactsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    private function named(\App\Models\Contacts $contact, string $first, string $last): void
    {
        foreach (['FIRST_NAME' => $first, 'LAST_NAME' => $last] as $tag => $value) {
            $field = ContactGroupFields::query()->where('contact_group_id', $contact->group_id)->where('tag', $tag)->first()
                ?? ContactGroupFields::create([
                    'contact_group_id' => $contact->group_id, 'label' => $tag, 'type' => 'text', 'tag' => $tag,
                    'visible' => true, 'required' => false, 'is_phone' => false,
                ]);

            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }
    }

    public function test_it_lists_this_businesss_contacts_by_name_and_number_only(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer'], ['automations', 'view_contact']);
        $this->named($t['contact'], 'Avery', 'Lopez');

        $other = $this->tenantWithWorkflow();
        $this->named($other['contact'], 'Someone', 'Else');

        $response = $this->callJson('GET', $this->routeUrl('test-contacts', $t['workspace'], $t['business'], $t['workflow']))
            ->assertOk()
            ->assertJsonStructure(['contacts' => [['uid', 'name', 'phone']]]);

        $contacts = $response->json('contacts');

        $this->assertSame([$t['contact']->uid], array_column($contacts, 'uid'), 'Only this Business\'s contacts are offered.');
        $this->assertSame('Avery Lopez', $contacts[0]['name']);
        $this->assertSame(['uid', 'name', 'phone'], array_keys($contacts[0]), 'Nothing beyond what the picker shows, plus the uid simulate needs.');
    }

    public function test_a_search_narrows_the_list(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer'], ['automations', 'view_contact']);
        $this->named($t['contact'], 'Avery', 'Lopez');

        $second = $this->contactFor($t['business']);
        $this->named($second, 'Jordan', 'Kim');

        $url = $this->routeUrl('test-contacts', $t['workspace'], $t['business'], $t['workflow']);

        $this->assertCount(2, $this->callJson('GET', $url)->assertOk()->json('contacts'));

        $found = $this->callJson('GET', $url . '?q=jordan')->assertOk()->json('contacts');
        $this->assertSame([$second->uid], array_column($found, 'uid'));

        $this->assertSame([], $this->callJson('GET', $url . '?q=nobody-by-this-name')->assertOk()->json('contacts'));
    }

    public function test_a_search_never_reaches_another_businesss_contacts(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer'], ['automations', 'view_contact']);

        $other = $this->tenantWithWorkflow();
        $this->named($other['contact'], 'Unique', 'Stranger');

        $found = $this->callJson('GET', $this->routeUrl('test-contacts', $t['workspace'], $t['business'], $t['workflow']) . '?q=Stranger')
            ->assertOk()
            ->json('contacts');

        $this->assertSame([], $found);
    }

    /** Managing automations does not by itself grant seeing the contact list. */
    public function test_seeing_contacts_needs_its_own_permission(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer'], ['automations']);

        $this->callJson('GET', $this->routeUrl('test-contacts', $t['workspace'], $t['business'], $t['workflow']))
            ->assertStatus(401)
            ->assertJsonMissingPath('contacts');
    }

    /** The loop the builder runs: pick a contact here, then test the workflow with it. */
    public function test_a_picked_contact_simulates(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer'], ['automations', 'view_contact']);

        $picked = $this->callJson('GET', $this->routeUrl('test-contacts', $t['workspace'], $t['business'], $t['workflow']))
            ->assertOk()
            ->json('contacts.0.uid');

        $this->callJson('POST', $this->routeUrl('simulate', $t['workspace'], $t['business'], $t['workflow']), ['contact_uid' => $picked])
            ->assertOk()
            ->assertJsonStructure(['path' => [['key', 'type', 'did']], 'ended']);
    }
}
