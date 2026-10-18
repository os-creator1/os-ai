<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * If / Else over Business-wide Custom Fields: `contact.field:{key}`, with the
 * operator family following the field's type, no generic expression language,
 * stable keys, archive determinism and legacy-vocabulary compatibility.
 */
class CustomFieldConditionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    private Business $business;

    private ContactGroups $group;

    private Contacts $contact;

    private CustomFieldDefinitionManager $fields;

    private CustomFieldValueService $values;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordingExecutor();
        [, $this->business] = $this->entitledTenant();
        $this->group = $this->contactGroup($this->business, 'Members');
        $this->contact = $this->contact($this->business, $this->group, '12025551234');
        $this->fields = app(CustomFieldDefinitionManager::class);
        $this->values = app(CustomFieldValueService::class);
    }

    private function field(string $label, string $type, mixed $value = null, ?array $options = null): CustomFieldDefinition
    {
        $field = $this->fields->create($this->business, $label, $type, $options);

        if ($value !== null) {
            $this->values->set($this->business, $this->contact, $field, $value);
        }

        return $field;
    }

    /** Publish a one-condition workflow for this Business, run it, and report the branch. */
    private function branch(array $conditions, string $match = 'all', ?Business $business = null, ?Contacts $contact = null): ?string
    {
        $business ??= $this->business;
        $contact ??= $this->contact;
        $drafts = app(WorkflowDraftService::class);
        $trigger = WorkflowTriggerType::ContactCreated;

        $workflow = $drafts->createWorkflowWithDraft($business, 'Branching ' . uniqid(), $trigger);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['next'] = [
            $this->ifElseStep($conditions, [$this->recordedStep('yes'), $this->endStep()], [$this->recordedStep('no'), $this->endStep()], $match),
        ];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id . uniqid());
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $this->branchTakenFor($enrollment);
    }

    /** @return array<string, list<string>> compile errors for a draft holding these conditions */
    private function compileErrors(Business $business, array $conditions): array
    {
        $drafts = app(WorkflowDraftService::class);
        $trigger = WorkflowTriggerType::ContactCreated;
        $workflow = $drafts->createWorkflowWithDraft($business, 'Compile ' . uniqid(), $trigger);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['next'] = [$this->ifElseStep($conditions, [$this->endStep()], [$this->endStep()])];
        $drafts->autosave($draft, $definition, $draft->definition_revision);

        return app(WorkflowCompiler::class)->validate($draft->fresh());
    }

    private function key(CustomFieldDefinition $field): string
    {
        return 'contact.field:' . $field->key;
    }

    public function test_a_date_field_is_set_not_set_before_and_after(): void
    {
        $date = $this->field('Event Date', 'date', '2027-06-14');
        $empty = $this->field('Other Date', 'date');

        $this->assertSame('yes', $this->branch([$this->condition($this->key($date), 'is_not_empty')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($empty), 'is_not_empty')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($empty), 'is_empty')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($date), 'before', '2027-07-01')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($date), 'before', '2027-06-01')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($date), 'after', '2027-06-01')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($date), 'on_date', '2027-06-14')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($empty), 'before', '2027-07-01')]), 'An unset date is neither before nor after.');
    }

    public function test_a_datetime_field_compares_as_a_date(): void
    {
        $when = $this->field('Event Start', 'datetime', '2027-06-14 18:30');

        $this->assertSame('yes', $this->branch([$this->condition($this->key($when), 'on_date', '2027-06-14')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($when), 'after', '2027-06-13')]));
    }

    public function test_a_number_field_supports_greater_and_less_than_and_exact_equality(): void
    {
        $guests = $this->field('Guest Count', 'number', '180');
        $budget = $this->field('Budget', 'currency', '2500.5');

        $this->assertSame('yes', $this->branch([$this->condition($this->key($guests), 'greater_than', '100')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($guests), 'greater_than', '180')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($guests), 'less_than', '200')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($guests), 'equals', '180')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($budget), 'equals', '2500.5')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($budget), 'equals', '2500')]), '2500.5 is not 2500.');
    }

    public function test_a_dropdown_equals_an_option_and_a_multi_select_includes_one(): void
    {
        $kind = $this->field('Event Type', 'select', 'Wedding', ['Wedding', 'Corporate']);
        $extras = $this->field('Extras', 'multi_select', ['Props', 'Prints'], ['Props', 'Backdrop', 'Prints']);
        [$wedding, $corporate] = array_column($kind->optionList(), 'id');
        [$props, $backdrop] = array_column($extras->optionList(), 'id');

        $this->assertSame('yes', $this->branch([$this->condition($this->key($kind), 'equals', $wedding)]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($kind), 'equals', $corporate)]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($kind), 'not_equals', $corporate)]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($extras), 'contains', $props)]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($extras), 'contains', $backdrop)]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($extras), 'not_contains', $backdrop)]));
    }

    public function test_a_yes_no_field_and_a_text_field(): void
    {
        $insured = $this->field('Insured', 'boolean', true);
        $notes = $this->field('Notes', 'text', 'Bring the arch');

        $this->assertSame('yes', $this->branch([$this->condition($this->key($insured), 'is_true')]));
        $this->assertSame('no', $this->branch([$this->condition($this->key($insured), 'is_false')]));
        $this->assertSame('yes', $this->branch([$this->condition($this->key($notes), 'contains', 'ARCH')]));
    }

    public function test_the_condition_survives_a_label_change_and_an_archive(): void
    {
        $guests = $this->field('Guest Count', 'number', '180');
        $condition = [$this->condition($this->key($guests), 'greater_than', '100')];
        $this->assertSame('yes', $this->branch($condition));

        $this->fields->update($this->business, $guests, 'Headcount');
        $this->assertSame('yes', $this->branch($condition), 'A new label does not change the key the condition reads.');

        $this->fields->archive($this->business, $guests);
        $this->assertSame('yes', $this->branch($condition), 'An archived field still reads: existing workflows stay deterministic.');
        $this->assertSame([], $this->compileErrors($this->business, $condition), 'An existing reference can still be saved.');
    }

    public function test_the_compiler_refuses_unknown_foreign_wrong_type_and_ill_fitting_conditions(): void
    {
        $guests = $this->field('Guest Count', 'number', '180');
        $date = $this->field('Event Date', 'date');
        $kind = $this->field('Event Type', 'select', null, ['Wedding', 'Corporate']);
        [, $other] = $this->entitledTenant();
        $foreign = $this->fields->create($other, 'Foreign Field', 'text');

        $bad = [
            'unknown key' => $this->condition('contact.field:nope', 'is_empty'),
            'another business' => $this->condition($this->key($foreign), 'is_empty'),
            'text operator on a number' => $this->condition($this->key($guests), 'contains', '1'),
            'greater than on a date' => $this->condition($this->key($date), 'greater_than', '3'),
            'non-numeric operand' => $this->condition($this->key($guests), 'greater_than', 'lots'),
            'bad date operand' => $this->condition($this->key($date), 'before', 'tomorrow'),
            'label instead of option id' => $this->condition($this->key($kind), 'equals', 'Wedding'),
        ];

        foreach ($bad as $why => $condition) {
            $this->assertNotSame([], $this->compileErrors($this->business, [$condition]), "{$why} must be refused");
        }

        $this->assertSame([], $this->compileErrors($this->business, [$this->condition($this->key($guests), 'greater_than', '100')]));
    }

    public function test_another_businesses_field_key_reads_as_false_never_as_a_value(): void
    {
        [, $other] = $this->entitledTenant();
        $foreign = $this->fields->create($other, 'Venue', 'text');
        $group = $this->contactGroup($other, 'Other');
        $otherContact = $this->contact($other, $group, '12025559999');
        $this->values->set($other, $otherContact, $foreign, 'Secret hall');

        // A condition written around the compiler (a forged config) must not read Business B's value from Business A.
        $subjects = app(\App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry::class);
        $subject = $subjects->find('contact.field:venue', (int) $this->business->id);

        $this->assertNotNull($subject);
        $this->assertNull($subject->valueFor($otherContact, new \App\Models\AutomationEnrollment()), 'A Contact of another Business reads as nothing.');
        $this->assertNull($subjects->find('contact.field:venue'), 'Without a Business the subject cannot be resolved.');
    }

    public function test_the_builders_reference_catalog_carries_custom_fields_in_its_single_statement(): void
    {
        $this->field('Event Date', 'date');
        $kind = $this->field('Event Type', 'select', null, ['Wedding', 'Corporate']);
        $this->fields->archive($this->business, $this->fields->create($this->business, 'Old Note', 'text'));
        [, $other] = $this->entitledTenant();
        $this->fields->create($other, 'Foreign Field', 'text');

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $catalog = app(\App\Library\Automation\Workflow\WorkflowReferenceCatalogLoader::class)->forBusiness($this->business);
        $statements = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $statements, 'Custom fields ride in the one catalog statement (§18 Builder budget).');
        $this->assertSame(['event_date', 'event_type', 'old_note'], array_column($catalog->customFields(), 'key'));
        $this->assertTrue($catalog->customField('old_note')['archived']);
        $this->assertSame($kind->optionList(), $catalog->customField('event_type')['options']);
        $this->assertNull($catalog->customField('foreign_field'), 'Another Business\'s field is not in this catalog.');
    }

    public function test_the_legacy_contact_group_field_subject_still_evaluates(): void
    {
        $fields = $this->identityFields($this->group, ['EVENT_TYPE']);
        $this->setContactValues($this->contact, $fields, ['EVENT_TYPE' => 'Wedding']);
        $legacy = 'contact.custom_field:' . $fields['EVENT_TYPE']->id;

        $this->assertSame('yes', $this->branch([$this->condition($legacy, 'equals', 'wedding')]));
        $this->assertSame([], $this->compileErrors($this->business, [$this->condition($legacy, 'equals', 'wedding')]));
    }
}
