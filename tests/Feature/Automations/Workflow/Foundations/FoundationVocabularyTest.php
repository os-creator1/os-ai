<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\NodeSideEffectClass;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Library\Crm\TagManager;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Automations x merged foundations — the engine's own vocabulary.
 *
 * What the registries, the compiler, the serializer and the simulator say about
 * the new triggers, actions and condition, and proof that the historical
 * vocabulary is exactly as it was.
 */
class FoundationVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use BuildsFoundationWorkflows;

    private const HISTORICAL_TRIGGERS = [
        'contact_created', 'contact_date_reached', 'manual_enrollment', 'message_received',
        'opportunity_created', 'opportunity_stage_changed', 'opportunity_won', 'opportunity_lost',
    ];

    private const HISTORICAL_NODES = ['trigger', 'send_sms', 'update_contact_field', 'internal_notification', 'wait', 'if_else', 'end'];

    private const NEW_TRIGGERS = [
        'contact_tag_added', 'contact_tag_removed', 'form_submitted',
        'appointment_scheduled', 'appointment_cancelled', 'appointment_rescheduled',
    ];

    private const NEW_NODES = ['send_email', 'add_tag', 'remove_tag'];

    // =================================================================
    // 20. The historical vocabulary is untouched
    // =================================================================

    public function test_every_historical_trigger_and_node_type_keeps_its_value_and_meaning(): void
    {
        foreach (self::HISTORICAL_TRIGGERS as $value) {
            $this->assertInstanceOf(WorkflowTriggerType::class, WorkflowTriggerType::tryFrom($value), $value);
        }

        foreach (self::HISTORICAL_NODES as $value) {
            $this->assertInstanceOf(WorkflowNodeType::class, WorkflowNodeType::tryFrom($value), $value);
        }

        // The vocabulary is exactly the historical set plus the merged-foundation additions: nothing else.
        $this->assertEqualsCanonicalizing(
            [...self::HISTORICAL_TRIGGERS, ...self::NEW_TRIGGERS],
            array_map(fn (WorkflowTriggerType $type) => $type->value, WorkflowTriggerType::cases()),
        );
        $this->assertEqualsCanonicalizing(
            [...self::HISTORICAL_NODES, ...self::NEW_NODES],
            array_map(fn (WorkflowNodeType $type) => $type->value, WorkflowNodeType::cases()),
        );

        // Historical side-effect classes and enrollment defaults did not move.
        $this->assertSame(NodeSideEffectClass::External, WorkflowNodeType::SendSms->sideEffectClass());
        $this->assertSame(NodeSideEffectClass::IdempotentDatabase, WorkflowNodeType::UpdateContactField->sideEffectClass());
        $this->assertSame(NodeSideEffectClass::None, WorkflowNodeType::IfElse->sideEffectClass());
        $this->assertSame(EnrollmentPolicy::OnceEver, WorkflowTriggerType::ContactCreated->defaultEnrollmentPolicy());
        $this->assertSame(EnrollmentPolicy::OncePerOccurrence, WorkflowTriggerType::OpportunityWon->defaultEnrollmentPolicy());
    }

    public function test_a_historical_workflow_still_compiles_publishes_and_runs(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [
            ['key' => (string) Str::uuid(), 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'minutes']],
            ['key' => (string) Str::uuid(), 'type' => 'internal_notification', 'config' => ['message' => 'Heads up']],
            [
                'key' => (string) Str::uuid(), 'type' => 'if_else',
                'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
                'yes' => [$this->endStep()],
                'no' => [],
            ],
        ]);

        $this->assertNotNull($workflow->published_version_id);
        $this->assertSame(
            ['trigger', 'wait', 'internal_notification', 'if_else', 'end'],
            DB::table('automation_workflow_nodes')->where('version_id', $workflow->published_version_id)->orderBy('id')->pluck('node_type')->all(),
        );

        $enrollment = app(\App\Library\Automation\Workflow\Contracts\EnrollmentService::class)->enroll($workflow, $contact, 'historical');
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($enrollment);

        // The wait parks the journey exactly as before.
        $this->assertSame('waiting', $enrollment->fresh()->status->value);
    }

    // =================================================================
    // 21. The new registry values serialize and deserialize
    // =================================================================

    public function test_new_enum_values_round_trip_and_every_trigger_and_node_has_its_runtime(): void
    {
        $sources = app(TriggerSourceRegistry::class);
        $executors = app(NodeExecutorRegistry::class);

        foreach (self::NEW_TRIGGERS as $value) {
            $type = WorkflowTriggerType::from($value);
            $this->assertSame($value, json_decode(json_encode(['t' => $type]), true)['t']);
            $this->assertNotSame('', $type->label());
            $this->assertSame(EnrollmentPolicy::OncePerOccurrence, $type->defaultEnrollmentPolicy());
            $this->assertTrue($type->isIngestableInThisSlice());
            $this->assertTrue($sources->available($type), "{$value} must have a registered, available source.");
        }

        foreach (WorkflowTriggerType::cases() as $type) {
            $this->assertTrue($sources->available($type), $type->value . ' must be listened to by a source.');
        }

        foreach (self::NEW_NODES as $value) {
            $type = WorkflowNodeType::from($value);
            $this->assertSame($value, json_decode(json_encode(['n' => $type]), true)['n']);
            $this->assertNotSame('', $type->label());
            $this->assertTrue($type->isPlaceableInBody());
            $this->assertTrue($executors->has($type), "{$value} must have a registered executor.");
        }

        foreach (WorkflowNodeType::cases() as $type) {
            $this->assertTrue($executors->has($type), $type->value . ' must have an executor.');
        }

        $this->assertSame(NodeSideEffectClass::External, WorkflowNodeType::SendEmail->sideEffectClass());
        $this->assertFalse(WorkflowNodeType::SendEmail->sideEffectClass()->isSafeToReExecute());
        $this->assertSame(NodeSideEffectClass::IdempotentDatabase, WorkflowNodeType::AddTag->sideEffectClass());
        $this->assertSame(NodeSideEffectClass::IdempotentDatabase, WorkflowNodeType::RemoveTag->sideEffectClass());
        $this->assertTrue(WorkflowTriggerType::ContactTagAdded->isContactTag());
        $this->assertTrue(WorkflowTriggerType::AppointmentRescheduled->isAppointment());
        $this->assertFalse(WorkflowTriggerType::FormSubmitted->isAppointment());
    }

    public function test_a_document_with_the_new_types_survives_save_publish_and_reload(): void
    {
        [, $business] = $this->crmTenant();
        $tag = app(TagManager::class)->createTag($business, 'VIP');
        $form = $this->makeFormFor($business);

        $steps = [$this->addTagStep((int) $tag->id), $this->emailStep('Subject', 'Body'), $this->removeTagStep((int) $tag->id), $this->endStep()];
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::FormSubmitted, ['form_id' => $form], $steps);

        $version = AutomationWorkflowVersion::query()->find($workflow->published_version_id);

        $this->assertSame(WorkflowTriggerType::FormSubmitted, $version->trigger_type);
        $this->assertSame(['add_tag', 'send_email', 'remove_tag', 'end'], array_map(fn ($node) => $node['type'], $version->definition['root']['next']));
        $this->assertSame($form, (int) $version->definition['root']['config']['form_id']);
        $this->assertEquals(['subject' => 'Subject', 'body' => 'Body'], $version->definition['root']['next'][1]['config'], 'MySQL JSON may reorder object keys.');
        $this->assertSame(
            ['trigger', 'add_tag', 'send_email', 'remove_tag', 'end'],
            DB::table('automation_workflow_nodes')->where('version_id', $version->id)->orderBy('id')->pluck('node_type')->all(),
        );
    }

    private function makeFormFor(\App\Models\Business $business): int
    {
        // A minimal form row is all the compiler's tenancy check needs.
        return (int) DB::table('forms')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Contact us',
            'lifecycle_state' => 'draft',
            'current_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =================================================================
    // 22. Validation and simulation understand the new config
    // =================================================================

    public function test_node_config_shapes_are_validated(): void
    {
        $registry = new NodeTypeRegistry();

        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::SendEmail, ['subject' => 'Hi', 'body' => 'There']));
        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::AddTag, ['tag_id' => 7]));
        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::RemoveTag, ['tag_id' => '7']));

        $subjectMax = (int) config('business_email.send.max_subject_length');
        $bodyMax = (int) config('business_email.send.max_body_length');

        foreach ([
            'no subject' => [['body' => 'x'], 'subject'],
            'blank subject' => [['subject' => '   ', 'body' => 'x'], 'subject'],
            'no body' => [['subject' => 'x'], 'email you want'],
            'subject too long' => [['subject' => str_repeat('s', $subjectMax + 1), 'body' => 'x'], 'subject is too long'],
            'body too long' => [['subject' => 'x', 'body' => str_repeat('b', $bodyMax + 1)], 'email is too long'],
        ] as $label => [$config, $needle]) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $registry->validateConfig(WorkflowNodeType::SendEmail, $config)), $label);
        }

        foreach ([WorkflowNodeType::AddTag, WorkflowNodeType::RemoveTag] as $type) {
            foreach ([[], ['tag_id' => null], ['tag_id' => 0], ['tag_id' => 'abc'], ['tag_id' => -1], ['tag_id' => 1.5]] as $config) {
                $this->assertSame(['Choose a tag.'], $registry->validateConfig($type, $config), json_encode($config));
            }
        }
    }

    public function test_trigger_filters_are_validated_as_optional_ids(): void
    {
        $registry = new NodeTypeRegistry();
        $base = fn (WorkflowTriggerType $type) => [
            'trigger_type' => $type->value,
            'enrollment_policy' => 'once_per_occurrence',
            'enrollment_policy_source' => 'default',
            'failure_policy' => 'halt',
        ];

        foreach ([WorkflowTriggerType::ContactTagAdded, WorkflowTriggerType::ContactTagRemoved] as $type) {
            $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base($type)), 'No filter means any tag.');
            $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base($type) + ['tag_id' => null]));
            $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base($type) + ['tag_id' => 5]));
            $this->assertNotSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base($type) + ['tag_id' => 'x']));
        }

        $form = $base(WorkflowTriggerType::FormSubmitted);
        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $form));
        $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $form + ['form_id' => 3]));
        $this->assertNotSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $form + ['form_id' => 'x']));

        foreach ([WorkflowTriggerType::AppointmentScheduled, WorkflowTriggerType::AppointmentCancelled, WorkflowTriggerType::AppointmentRescheduled] as $type) {
            $this->assertSame([], $registry->validateConfig(WorkflowNodeType::Trigger, $base($type)));
        }
    }

    public function test_the_stale_default_rule_applies_to_the_new_triggers(): void
    {
        // A once_ever policy still claiming to be a default on a per-occurrence trigger is refused.
        $errors = (new NodeTypeRegistry())->validateConfig(WorkflowNodeType::Trigger, [
            'trigger_type' => 'form_submitted',
            'enrollment_policy' => 'once_ever',
            'enrollment_policy_source' => 'default',
            'failure_policy' => 'halt',
        ]);

        $this->assertNotSame([], $errors);

        // The same choice made deliberately is allowed.
        $this->assertSame([], (new NodeTypeRegistry())->validateConfig(WorkflowNodeType::Trigger, [
            'trigger_type' => 'form_submitted',
            'enrollment_policy' => 'once_ever',
            'enrollment_policy_source' => 'user',
            'failure_policy' => 'halt',
        ]));
    }

    public function test_has_tag_subjects_are_known_strictly(): void
    {
        $this->assertTrue(ConditionSubjectRegistry::isKnownSubjectKey('contact.has_tag:12'));
        $this->assertSame(12, ConditionSubjectRegistry::tagId('contact.has_tag:12'));

        foreach (['contact.has_tag:', 'contact.has_tag:0', 'contact.has_tag:-3', 'contact.has_tag:1x', 'contact.has_tag:1.5', 'contact.has_tag'] as $bad) {
            $this->assertFalse(ConditionSubjectRegistry::isKnownSubjectKey($bad), $bad);
            $this->assertNull(ConditionSubjectRegistry::tagId($bad), $bad);
        }

        $this->assertSame(
            \App\Enums\Automation\Workflow\ConditionOperator::forBoolean(),
            ConditionSubjectRegistry::staticOperatorsFor('contact.has_tag:12'),
        );
    }

    public function test_the_simulator_walks_the_new_steps_describing_them_and_changing_nothing(): void
    {
        [, $business] = $this->crmTenant();
        $business = $this->activate($business);
        $contact = $this->crmContact($business);
        $tag = app(TagManager::class)->createTag($business, 'VIP');
        app(TagManager::class)->attachTag($business, $contact, $tag);

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [
            [
                'key' => (string) Str::uuid(), 'type' => 'if_else',
                'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.has_tag:' . $tag->id, 'operator' => 'is_true']]],
                'yes' => [$this->emailStep(), $this->addTagStep((int) $tag->id), $this->removeTagStep((int) $tag->id)],
                'no' => [$this->endStep()],
            ],
        ]);

        $tables = ['contact_tags', 'automation_enrollments', 'automation_step_runs', 'business_email_messages', 'contacts'];
        $before = array_map(fn ($table) => DB::table($table)->count(), $tables);

        $result = app(WorkflowSimulator::class)->simulate(AutomationWorkflowVersion::query()->find($workflow->published_version_id), $contact);

        $this->assertNull($result['refused']);
        $this->assertSame([], $result['validation']);
        $this->assertSame(['trigger', 'if_else', 'send_email', 'add_tag', 'remove_tag'], array_column($result['steps'], 'type'));

        $byType = collect($result['steps'])->keyBy('type');
        $this->assertSame('yes', $byType['if_else']['branch'], 'The real has-tag condition ran against the real contact.');

        foreach (['send_email' => 'Nothing is sent', 'add_tag' => 'Nothing is changed', 'remove_tag' => 'Nothing is changed'] as $type => $words) {
            $this->assertTrue($byType[$type]['would_run'], $type);
            $this->assertStringContainsString($words, (string) $byType[$type]['detail'], $type);
        }

        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), $tables), 'Simulating wrote nothing.');
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->count(), 'The tag is still on the contact.');
    }

    public function test_the_compiler_reports_missing_and_foreign_references_by_node(): void
    {
        [, $business] = $this->crmTenant('Business A', 'Workspace A');
        [, $other] = $this->crmTenant('Business B', 'Workspace B');
        $foreign = app(TagManager::class)->createTag($other, 'Theirs');
        $drafts = app(WorkflowDraftService::class);

        $workflow = $drafts->createWorkflowWithDraft($business, 'Bad references', WorkflowTriggerType::ContactTagAdded);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactTagAdded);
        $definition['root']['config']['tag_id'] = (int) $foreign->id;
        $emailKey = (string) Str::uuid();
        $definition['root']['next'] = [
            ['key' => $emailKey, 'type' => 'send_email', 'config' => ['subject' => '', 'body' => '']],
            $this->endStep(),
        ];
        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        // Shape comes first: an incomplete email step is reported on its own node.
        $errors = app(WorkflowCompiler::class)->validate($draft->fresh());
        $this->assertArrayHasKey($emailKey, $errors, 'The email step is incomplete.');

        // Once the shape is sound, tenancy is checked against real rows, node by node.
        $definition['root']['next'] = [$this->addTagStep((int) $foreign->id), $this->endStep()];
        $drafts->autosave($draft->fresh(), $definition, (int) $draft->fresh()->definition_revision);

        $errors = app(WorkflowCompiler::class)->validate($draft->fresh());
        $this->assertArrayHasKey($definition['root']['key'], $errors, 'The trigger filter names a foreign tag.');
        $this->assertArrayHasKey($definition['root']['next'][0]['key'], $errors, 'The add-tag step names a foreign tag.');
    }
}
