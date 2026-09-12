<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §11 — the If / Else step.
 *
 * Two families of proof. The first is that the decision is right: each subject
 * reads what it claims to, each operator family behaves, and `all`/`any` combine
 * as advertised. The second is that a wrong or hostile configuration cannot make
 * it read something it should not — a foreign group, another Business's field, a
 * field belonging to a group this contact is not in.
 */
class IfElseExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->recordingExecutor();
    }

    /**
     * Publish a one-condition workflow, run it, and report which branch it took.
     *
     * @param list<array<string, mixed>> $conditions
     */
    private function branchFor(
        Business $business,
        ContactGroups $group,
        Contacts $contact,
        array $conditions,
        string $match = 'all',
    ): ?string {
        [$workflow] = $this->publishGroupScopedIfElse($business, $group, $conditions, $match);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $this->branchTakenFor($enrollment);
    }

    /**
     * @param list<array<string, mixed>> $conditions
     *
     * @return array{0: \App\Models\AutomationWorkflow, 1: \App\Models\AutomationWorkflowVersion}
     */
    private function publishGroupScopedIfElse(
        Business $business,
        ContactGroups $group,
        array $conditions,
        string $match = 'all',
        array $yes = null,
        array $no = null,
    ): array {
        $drafts = app(\App\Library\Automation\Workflow\WorkflowDraftService::class);
        $trigger = \App\Enums\Automation\Workflow\WorkflowTriggerType::ContactCreated;

        $workflow = $drafts->createWorkflowWithDraft($business, 'Branching ' . uniqid(), $trigger);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = [
            $this->ifElseStep(
                $conditions,
                $yes ?? [$this->recordedStep('yes branch'), $this->endStep()],
                $no ?? [$this->recordedStep('no branch'), $this->endStep()],
                $match,
            ),
        ];

        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $version = app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh());

        return [$workflow->fresh(), $version];
    }

    /** @return array{0: Business, 1: ContactGroups, 2: Contacts, 3: array<string, ContactGroupFields>} */
    private function tenantWithIdentity(array $values = [], array $extraTags = []): array
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members ' . uniqid());
        $fields = $this->identityFields($group, $extraTags);
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));

        $this->setContactValues($contact, $fields, $values);

        return [$business, $group, $contact->fresh(), $fields];
    }

    /** 21. `all` with every condition true takes yes. */
    public function test_all_match_takes_the_yes_branch(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity([
            'FIRST_NAME' => 'Ada', 'COMPANY' => 'Analytical Engines',
        ]);

        $branch = $this->branchFor($business, $group, $contact, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
            $this->condition('contact.company', 'contains', 'Engines'),
        ]);

        $this->assertSame('yes', $branch);
    }

    /** 22. `all` with one condition false takes no. */
    public function test_all_match_with_one_failure_takes_the_no_branch(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity([
            'FIRST_NAME' => 'Ada', 'COMPANY' => 'Analytical Engines',
        ]);

        $branch = $this->branchFor($business, $group, $contact, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
            $this->condition('contact.company', 'equals', 'Somewhere else'),
        ]);

        $this->assertSame('no', $branch);
    }

    /** 23. `any` with one condition true takes yes. */
    public function test_any_match_takes_yes_on_a_single_hit(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        $branch = $this->branchFor($business, $group, $contact, [
            $this->condition('contact.first_name', 'equals', 'Grace'),
            $this->condition('contact.first_name', 'equals', 'Ada'),
        ], 'any');

        $this->assertSame('yes', $branch);
    }

    /** 24. `any` with nothing true takes no. */
    public function test_any_match_takes_no_when_nothing_hits(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        $branch = $this->branchFor($business, $group, $contact, [
            $this->condition('contact.first_name', 'equals', 'Grace'),
            $this->condition('contact.last_name', 'equals', 'Hopper'),
        ], 'any');

        $this->assertSame('no', $branch);
    }

    /** 25. Every launch subject reads what it claims to. */
    public function test_each_launch_subject_reads_its_own_value(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity([
            'FIRST_NAME' => 'Ada',
            'LAST_NAME' => 'Lovelace',
            'EMAIL' => 'ada@example.test',
            'COMPANY' => 'Analytical Engines',
            'NOTE' => 'vip',
        ], ['NOTE']);

        $cases = [
            [$this->condition('contact.first_name', 'equals', 'Ada'), 'yes'],
            [$this->condition('contact.last_name', 'equals', 'Lovelace'), 'yes'],
            [$this->condition('contact.email', 'contains', '@example.test'), 'yes'],
            [$this->condition('contact.company', 'equals', 'Analytical Engines'), 'yes'],
            [$this->condition('contact.subscribed', 'is_true'), 'yes'],
            [$this->condition('contact.subscribed', 'is_false'), 'no'],
            [$this->condition('contact.in_group', 'equals', (string) $group->id), 'yes'],
            [$this->condition('contact.in_group', 'not_equals', (string) $group->id), 'no'],
            [$this->condition('contact.custom_field:' . $fields['NOTE']->id, 'equals', 'vip'), 'yes'],
            [$this->condition('contact.custom_field:' . $fields['NOTE']->id, 'equals', 'other'), 'no'],
        ];

        foreach ($cases as [$condition, $expected]) {
            $this->assertSame(
                $expected,
                $this->branchFor($business, $group, $contact, [$condition]),
                'Subject ' . $condition['subject'] . ' ' . $condition['operator'],
            );
        }
    }

    /** 26. Every operator family behaves. */
    public function test_every_operator_family_behaves(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity([
            'FIRST_NAME' => 'Ada',
            'EMAIL' => '',
            'BIRTHDAY' => '2026-06-15',
        ], ['BIRTHDAY']);

        // The date field must really be a date for its operator family to apply.
        ContactGroupFields::query()->whereKey($fields['BIRTHDAY']->id)
            ->update(['type' => ContactGroupFields::TYPE_DATE]);

        $birthday = 'contact.custom_field:' . $fields['BIRTHDAY']->id;

        $cases = [
            // Text
            [$this->condition('contact.first_name', 'equals', 'Ada'), 'yes'],
            [$this->condition('contact.first_name', 'not_equals', 'Grace'), 'yes'],
            [$this->condition('contact.first_name', 'contains', 'd'), 'yes'],
            [$this->condition('contact.first_name', 'not_contains', 'z'), 'yes'],
            [$this->condition('contact.email', 'is_empty'), 'yes'],
            [$this->condition('contact.first_name', 'is_not_empty'), 'yes'],
            // Case and whitespace insensitivity, which small businesses rely on.
            [$this->condition('contact.first_name', 'equals', '  ADA '), 'yes'],
            // Boolean
            [$this->condition('contact.subscribed', 'is_true'), 'yes'],
            // Reference
            [$this->condition('contact.in_group', 'equals', (string) $group->id), 'yes'],
            // Date
            [$this->condition($birthday, 'before', '2026-07-01'), 'yes'],
            [$this->condition($birthday, 'after', '2026-01-01'), 'yes'],
            [$this->condition($birthday, 'on_date', '2026-06-15'), 'yes'],
            [$this->condition($birthday, 'before', '2026-01-01'), 'no'],
        ];

        foreach ($cases as [$condition, $expected]) {
            $this->assertSame(
                $expected,
                $this->branchFor($business, $group, $contact, [$condition]),
                $condition['subject'] . ' ' . $condition['operator'],
            );
        }
    }

    /** 27. An operator the subject does not accept is refused at publish. */
    public function test_an_invalid_operator_is_refused(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();

        $this->expectException(\Throwable::class);

        // `contains` is meaningless for a yes/no subject.
        $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.subscribed', 'contains', 'yes'),
        ]);
    }

    /** 27b. An unknown subject is refused too — there is no expression language. */
    public function test_an_unknown_subject_is_refused(): void
    {
        [$business, $group] = $this->tenantWithIdentity();

        $this->expectException(\Throwable::class);

        $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.salary', 'equals', '1'),
        ]);
    }

    /** 27c. A date field cannot be asked a text question. */
    public function test_a_date_field_refuses_a_text_operator(): void
    {
        [$business, $group, , $fields] = $this->tenantWithIdentity([], ['BIRTHDAY']);

        ContactGroupFields::query()->whereKey($fields['BIRTHDAY']->id)
            ->update(['type' => ContactGroupFields::TYPE_DATE]);

        $this->expectException(\Throwable::class);

        $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.custom_field:' . $fields['BIRTHDAY']->id, 'contains', 'June'),
        ]);
    }

    /** 28. A group belonging to another Business is refused at publish. */
    public function test_a_foreign_group_is_refused(): void
    {
        [$business, $group] = $this->tenantWithIdentity();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness, 'Theirs');

        $this->expectException(\Throwable::class);

        $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.in_group', 'equals', (string) $foreignGroup->id),
        ]);
    }

    /** 29. A custom field belonging to another Business is refused at publish. */
    public function test_a_foreign_custom_field_is_refused(): void
    {
        [$business, $group] = $this->tenantWithIdentity();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness, 'Theirs');
        $foreignField = $this->textField($foreignGroup, 'THEIR_NOTE');

        $this->expectException(\Throwable::class);

        $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.custom_field:' . $foreignField->id, 'equals', 'x'),
        ]);
    }

    /**
     * 28b/29b. And if a foreign reference reaches the compiled graph anyway,
     * evaluation refuses it too — the condition is false, never a foreign read.
     */
    public function test_a_tampered_foreign_reference_evaluates_false(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity(['NOTE' => 'vip'], ['NOTE']);

        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness, 'Theirs');
        $foreignField = $this->textField($foreignGroup, 'THEIR_NOTE');
        $foreignContact = $this->contact($otherBusiness, $foreignGroup, '12025559999');
        $this->setContactValues($foreignContact, ['THEIR_NOTE' => $foreignField], ['THEIR_NOTE' => 'vip']);

        // Publish something legitimate, then repoint the compiled node at the
        // other Business's field with a value that WOULD match if it were read.
        [$workflow, $version] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.custom_field:' . $fields['NOTE']->id, 'equals', 'vip'),
        ]);

        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'if_else')
            ->update(['config' => json_encode([
                'match' => 'all',
                'conditions' => [[
                    'subject' => 'contact.custom_field:' . $foreignField->id,
                    'operator' => 'equals',
                    'operand' => 'vip',
                ]],
            ])]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(
            'no',
            $this->branchTakenFor($enrollment),
            'A field outside this Business must never influence a branch.',
        );
    }

    /** 30. A contact outside the field's group reads as empty, per §11. */
    public function test_a_contact_outside_the_fields_group_reads_empty(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        // A second group of the SAME Business, with its own field and a value
        // that would match if the wrong row were read.
        $otherGroup = $this->contactGroup($business, 'Other ' . uniqid());
        $otherField = $this->textField($otherGroup, 'OTHER_NOTE');
        $otherContact = $this->contact($business, $otherGroup, '12025558888');
        $this->setContactValues($otherContact, ['OTHER_NOTE' => $otherField], ['OTHER_NOTE' => 'vip']);

        // The workflow watches $group, but asks about $otherGroup's field.
        [$workflow, $version] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
        ]);

        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'if_else')
            ->update(['config' => json_encode([
                'match' => 'all',
                'conditions' => [
                    ['subject' => 'contact.custom_field:' . $otherField->id, 'operator' => 'is_empty'],
                ],
            ])]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(
            'yes',
            $this->branchTakenFor($enrollment),
            'A field of a group this contact is not in reads as empty, not as somebody else\'s value.',
        );
    }

    /** 31. Only the yes lane runs. */
    public function test_only_the_yes_branch_executes(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [$workflow, $version] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $yesNode = $this->nodeIdByBody((int) $version->id, 'yes branch');
        $noNode = $this->nodeIdByBody((int) $version->id, 'no branch');

        $this->assertSame(1, $this->executor->callsForNode($yesNode));
        $this->assertSame(0, $this->executor->callsForNode($noNode), 'The other lane must never run.');
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    /** 32. Only the no lane runs. */
    public function test_only_the_no_branch_executes(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [$workflow, $version] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.first_name', 'equals', 'Grace'),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $yesNode = $this->nodeIdByBody((int) $version->id, 'yes branch');
        $noNode = $this->nodeIdByBody((int) $version->id, 'no branch');

        $this->assertSame(0, $this->executor->callsForNode($yesNode));
        $this->assertSame(1, $this->executor->callsForNode($noNode));
    }

    /** 33. An empty selected lane simply completes the journey. */
    public function test_an_empty_selected_branch_completes_the_journey(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [$workflow] = $this->publishGroupScopedIfElse(
            $business,
            $group,
            [$this->condition('contact.first_name', 'equals', 'Ada')],
            'all',
            yes: [],
            no: [$this->recordedStep('no branch'), $this->endStep()],
        );

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame('yes', $this->branchTakenFor($enrollment));
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(0, $this->executor->callCount(), 'An empty lane runs nothing at all.');
    }

    /** 34. The branch taken is persisted on the step run. */
    public function test_the_branch_taken_is_persisted(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [$workflow] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $stepRun = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)->where('node_type', 'if_else')->first();

        $this->assertSame('yes', $stepRun->branch_taken);
        $this->assertSame('succeeded', $stepRun->status);
    }

    /**
     * 35. Once a branch is taken it stays taken. Re-advancing after the contact's
     * data changes must not re-evaluate into the other lane — the step run is
     * already complete, so the claim refuses and the recorded branch stands.
     */
    public function test_a_repeated_advance_cannot_switch_the_branch(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [$workflow, $version] = $this->publishGroupScopedIfElse($business, $group, [
            $this->condition('contact.first_name', 'equals', 'Ada'),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        $advancer = app(WorkflowAdvancer::class);
        $advancer->advance($enrollment);

        $this->assertSame('yes', $this->branchTakenFor($enrollment));

        // The answer would now be different.
        $this->setContactValues($contact, $fields, ['FIRST_NAME' => 'Grace']);

        for ($i = 0; $i < 3; $i++) {
            $advancer->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame('yes', $this->branchTakenFor($enrollment), 'The recorded branch is final.');
        $this->assertSame(
            1,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)
                ->where('node_type', 'if_else')->count(),
            'One evaluation, one step run.',
        );
        $this->assertSame(0, $this->executor->callsForNode($this->nodeIdByBody((int) $version->id, 'no branch')));
    }

    /**
     * 36. Reads stay bounded: five conditions cost no more queries than one,
     * because the contact's values and its group's fields are each read once.
     */
    public function test_the_query_count_does_not_grow_with_the_condition_count(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity([
            'FIRST_NAME' => 'Ada', 'LAST_NAME' => 'Lovelace',
            'EMAIL' => 'ada@example.test', 'COMPANY' => 'Engines',
        ]);

        $executor = app(\App\Library\Automation\Workflow\Executors\IfElseNodeExecutor::class);

        $one = $this->queriesForConditions($executor, $business, $group, $contact, 1);
        $five = $this->queriesForConditions($executor, $business, $group, $contact, 5);

        $this->assertSame(
            $one,
            $five,
            "Five identity conditions must cost the same reads as one ({$one} vs {$five}).",
        );
        $this->assertLessThanOrEqual(4, $five, 'And that cost must be a small constant.');
    }

    private function queriesForConditions(
        \App\Library\Automation\Workflow\Executors\IfElseNodeExecutor $executor,
        Business $business,
        ContactGroups $group,
        Contacts $contact,
        int $count,
    ): int {
        $conditions = [];

        for ($i = 0; $i < $count; $i++) {
            $conditions[] = $this->condition('contact.first_name', 'equals', 'Ada');
        }

        $node = new \App\Models\AutomationWorkflowNode();
        $node->config = ['match' => 'all', 'conditions' => $conditions];

        $enrollment = new \App\Models\AutomationEnrollment();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $executor->execute($node, $enrollment, $business, $contact->fresh());

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
