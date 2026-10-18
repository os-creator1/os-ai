<?php

namespace Tests\Feature\V1Acceptance\LeadLifecycle;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Models\AutomationStepRun;
use App\Models\Contacts;
use Illuminate\Support\Str;

/**
 * Custom fields, end to end: the value a Form writes, an Automation writes, the Contact page shows
 * and a merge field renders are ONE canonical value (custom_field_values), not four copies.
 */
class CanonicalCustomFieldsJourneyTest extends LeadLifecycleTestCase
{
    private function setFieldStep(int $id, string $value): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'update_contact_field', 'config' => ['custom_field_id' => $id, 'value' => $value]];
    }

    public function test_a_foreign_or_archived_field_cannot_be_published_and_a_bad_value_writes_nothing(): void
    {
        $fields = app(CustomFieldDefinitionManager::class);

        [$foreignBusiness] = $this->foreignBusinessWithLocation();
        $foreign = $fields->create($foreignBusiness, 'Their field', 'text');
        $archived = $fields->create($this->business, 'Old field', 'text');
        $fields->archive($this->business, $archived);

        foreach ([$foreign, $archived] as $unusable) {
            try {
                $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, [], [$this->setFieldStep((int) $unusable->id, 'x'), $this->endStep()]);
                $this->fail('A workflow naming a foreign or archived field must not publish.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // A value the field's own type refuses (text into a date field) is skipped, never half-written.
        $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, ['form_id' => $this->form->id], [$this->setFieldStep((int) $this->eventDate->id, 'next summer'), $this->endStep()]);
        $this->submitForm($this->formA, ['event_date' => ''])->assertRedirect();
        $contact = Contacts::query()->sole();
        $this->xAdvanceAll();
        $this->assertNull($this->canonical($contact, $this->eventDate));
    }

    public function test_the_per_list_field_action_still_works_for_a_contact_group_trigger(): void
    {
        $group = \App\Models\ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Legacy list', 'status' => true]);
        $field = \App\Models\ContactGroupFields::create(['contact_group_id' => $group->id, 'label' => 'Notes', 'type' => 'text', 'tag' => 'NOTES', 'visible' => true, 'required' => false, 'is_phone' => false]);

        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $trigger = WorkflowTriggerType::ContactCreated;
        $workflow = $drafts->createWorkflowWithDraft($this->business, 'Legacy field', $trigger);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = [['key' => (string) Str::uuid(), 'type' => 'update_contact_field', 'config' => ['field_id' => $field->id, 'value' => 'Legacy note']], $this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh());

        $contact = Contacts::create(['customer_id' => $group->customer_id, 'business_id' => $this->business->id, 'location_id' => $this->locationA->id, 'group_id' => $group->id, 'phone' => '14155550166', 'status' => Contacts::STATUS_SUBSCRIBE]);
        app(\App\Library\Automation\Workflow\Contracts\EnrollmentService::class)->enroll($workflow->fresh(), $contact, 'legacy-1', 0, null);
        $this->xAdvanceAll();

        $this->assertSame('Legacy note', \App\Models\ContactsCustomField::query()->where('contact_id', $contact->id)->where('field_id', $field->id)->value('value'));
    }

    public function test_form_automation_contact_page_and_merge_fields_share_the_same_canonical_values(): void
    {
        $package = app(CustomFieldDefinitionManager::class)->create($this->business, 'Package', 'text');
        // A workflow on a FORM trigger (no contact group) that sets a canonical Business field.
        $workflow = $this->triggerWorkflow($this->business, WorkflowTriggerType::FormSubmitted, ['form_id' => $this->form->id], [
            $this->setFieldStep((int) $package->id,'Gold booth'),
            $this->endStep(),
        ]);

        $this->submitForm($this->formA, ['event_type' => 'Wedding'])->assertRedirect();
        $contact = Contacts::query()->sole();
        $this->assertSame(1, $this->enrollmentCount($workflow));
        $this->xAdvanceAll();

        // Form-written and automation-written values live in the same canonical store.
        $this->assertSame('Wedding', $this->canonical($contact, $this->eventType));
        $this->assertSame('Gold booth', $this->canonical($contact, $package));
        $this->assertSame(1, AutomationStepRun::query()->where('node_type', 'update_contact_field')->count(), 'The step ran once.');

        // Merge fields read the same values.
        $this->assertSame('Wedding / Gold booth', ContactMergeFields::render('{{contact.event_type}} / {{contact.package}}', $contact->fresh()));

        // The Contact page shows the same values.
        $this->actAsOwnerInCrm();
        $this->get($this->peopleShow($contact))->assertOk()->assertSee('Wedding')->assertSee('Gold booth');

        // Replaying the step's trigger does nothing more.
        $this->submitForm($this->formA, ['event_type' => 'Wedding'], null)->assertRedirect();
        $this->assertSame(1, Contacts::query()->count());
    }
}
