<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Automation\Workflow\WorkflowSimulator;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2 §16 / T-WF-16 — "Test workflow".
 *
 * The simulator's whole promise is a negative one, so most of this file is spent
 * proving absence: that after asking what a workflow WOULD do, every table holds
 * exactly the rows it held before, no job was queued, no message or notification
 * left, no contact changed, and nothing reached the network.
 *
 * The positive half is that it still answers correctly — the same Yes/No choice
 * the real If/Else executor makes, the same wait instant the real Wait executor
 * computes, the action steps named but not performed — and that asking twice
 * gives the same answer.
 */
class SimulatorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    /** Every table a simulation must leave untouched. */
    private const WATCHED_TABLES = [
        'automation_enrollments',
        'automation_step_runs',
        'automation_workflows',
        'automation_workflow_versions',
        'automation_workflow_nodes',
        'automation_workflow_edges',
        'contacts',
        'contacts_custom_field',
        'reports',
        'notifications',
    ];

    private function simulator(): WorkflowSimulator
    {
        return app(WorkflowSimulator::class);
    }

    /** @return array<string, int> table => row count */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::WATCHED_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Publish a workflow whose body is the given steps.
     *
     * @param list<array<string, mixed>> $steps
     * @return array{0: AutomationWorkflow, 1: AutomationWorkflowVersion}
     */
    private function publishBody(Business $business, ContactGroups $group, array $steps): array
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Simulated ' . uniqid(), WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = $steps;

        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $version = app(WorkflowPublisher::class)->publish($workflow->fresh());

        return [$workflow->fresh(), $version];
    }

    /** A draft — never published — whose body is the given steps. */
    private function draftBody(Business $business, ContactGroups $group, array $steps): AutomationWorkflowVersion
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Drafted ' . uniqid(), WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = $steps;

        $drafts->autosave($draft, $definition, $draft->definition_revision);

        return $draft->fresh();
    }

    /** @return array{0: Business, 1: ContactGroups, 2: Contacts, 3: array<string, ContactGroupFields>} */
    private function tenantWithIdentity(array $values = []): array
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members ' . uniqid());
        $fields = $this->identityFields($group);
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));

        $this->setContactValues($contact, $fields, $values);

        return [$business, $group, $contact->fresh(), $fields];
    }

    /** @param list<array<string, mixed>> $steps */
    private function stepTypes(array $steps): array
    {
        return array_map(fn (array $step): string => $step['type'], $steps);
    }

    // =================================================================
    // T-WF-16 — it writes nothing, sends nothing, queues nothing
    // =================================================================

    public function test_a_full_simulation_changes_no_row_and_sends_nothing(): void
    {
        Queue::fake();
        Bus::fake();
        Notification::fake();
        Http::fake();

        [$business, $group, $contact, $fields] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        // One of everything: a real send, a real field write, a real
        // notification, a wait, and a branch — every class of side effect the
        // engine has.
        [, $version] = $this->publishBody($business, $group, [
            $this->waitStep(2, 'days'),
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [
                    $this->recordedStep('yes message'),
                    $this->updateFieldStep($fields['LAST_NAME'], 'Lovelace'),
                    $this->endStep(),
                ],
                [$this->recordedStep('no message'), $this->endStep()],
            ),
        ]);

        $before = $this->rowCounts();
        $beforeContact = DB::table('contacts')->where('id', $contact->id)->first();
        $beforeValues = DB::table('contacts_custom_field')->where('contact_id', $contact->id)
            ->orderBy('field_id')->pluck('value', 'field_id')->all();

        $result = $this->simulator()->simulate($version, $contact);

        $this->assertNull($result['refused']);
        $this->assertNotSame([], $result['steps']);

        // The whole point, in four assertions.
        $this->assertSame($before, $this->rowCounts(), 'A simulation must not change any row count.');
        $this->assertEquals($beforeContact, DB::table('contacts')->where('id', $contact->id)->first());
        $this->assertSame($beforeValues, DB::table('contacts_custom_field')->where('contact_id', $contact->id)
            ->orderBy('field_id')->pluck('value', 'field_id')->all(), 'No contact field may be written.');
        Http::assertNothingSent();

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
    }

    public function test_the_action_steps_are_described_and_never_executed(): void
    {
        Queue::fake();
        Notification::fake();
        Http::fake();

        [$business, $group, $contact, $fields] = $this->tenantWithIdentity();

        [, $version] = $this->publishBody($business, $group, [
            $this->recordedStep('hello'),
            $this->updateFieldStep($fields['COMPANY'], 'Analytical Engines'),
            $this->notifyStep(),
            $this->endStep(),
        ]);

        $result = $this->simulator()->simulate($version, $contact);

        $byType = [];

        foreach ($result['steps'] as $step) {
            $byType[$step['type']] = $step;
        }

        foreach (['send_sms', 'update_contact_field', 'internal_notification'] as $type) {
            $this->assertArrayHasKey($type, $byType, $type . ' must appear in the simulated path.');
            $this->assertTrue($byType[$type]['would_run'], $type . ' must be reported as a step that WOULD run.');
            $this->assertSame(WorkflowSimulator::DID_WOULD_RUN, $byType[$type]['did']);
            $this->assertNotNull($byType[$type]['detail'], 'A would-run step must say what it would do.');
        }

        // An update_contact_field is "idempotent" for recovery and still a
        // write, so it is described here exactly like a send is.
        $this->assertSame('idempotent_database', $byType['update_contact_field']['side_effect']);
        $this->assertSame('external', $byType['send_sms']['side_effect']);

        $this->assertNotSame(
            'Analytical Engines',
            DB::table('contacts_custom_field')
                ->where('contact_id', $contact->id)
                ->where('field_id', $fields['COMPANY']->id)
                ->value('value'),
            'The field the workflow would write must still be unwritten.',
        );

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_no_enrollment_and_no_step_run_is_created(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();
        [, $version] = $this->publishBody($business, $group, [$this->recordedStep('hi'), $this->endStep()]);

        $this->simulator()->simulate($version, $contact);

        $this->assertSame(0, DB::table('automation_enrollments')->count(), 'Simulation never enrolls.');
        $this->assertSame(0, DB::table('automation_step_runs')->count(), 'Simulation never records a step run.');
    }

    public function test_no_job_is_queued_and_no_wait_is_entered(): void
    {
        Queue::fake();

        [$business, $group, $contact] = $this->tenantWithIdentity();
        [, $version] = $this->publishBody($business, $group, [
            $this->waitStep(3, 'days'),
            $this->recordedStep('after the wait'),
            $this->endStep(),
        ]);

        $result = $this->simulator()->simulate($version, $contact);

        // §12 / T-WF-12 — nothing delayed, nothing queued at all.
        Queue::assertNothingPushed();

        $this->assertSame(0, DB::table('automation_enrollments')->where('status', 'waiting')->count());
        $this->assertSame(0, DB::table('automation_enrollments')->whereNotNull('resume_at')->count());

        // And the walk continued past the wait rather than stopping at it.
        $this->assertSame(
            ['trigger', 'wait', 'send_sms', 'end'],
            $this->stepTypes($result['steps']),
        );
    }

    // =================================================================
    // It still answers correctly
    // =================================================================

    public function test_the_yes_branch_is_the_one_the_real_executor_would_take(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        $result = $this->simulator()->simulate($version, $contact);
        $branch = $this->branchStep($result);

        $this->assertSame('yes', $branch['branch']);
        $this->assertSame(WorkflowSimulator::DID_BRANCHED, $branch['did']);
        $this->assertSame(
            'yes branch',
            $this->bodyOfFirstSms($result),
            'The path must continue down the lane the condition chose.',
        );
        $this->assertSame(WorkflowSimulator::ENDED_END_STEP, $result['ended']);
    }

    public function test_the_no_branch_is_deterministic_too(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Grace']);

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        $result = $this->simulator()->simulate($version, $contact);

        $this->assertSame('no', $this->branchStep($result)['branch']);
        $this->assertSame('no branch', $this->bodyOfFirstSms($result));
    }

    public function test_a_wait_is_previewed_with_the_instant_the_runtime_would_store(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();
        $this->setBusinessTimezone($business, 'America/New_York');

        [, $version] = $this->publishBody($business, $group, [
            $this->waitStep(2, 'days'),
            $this->endStep(),
        ]);

        $result = $this->simulator()->simulate($version->fresh(), $contact);
        $wait = $this->stepOfType($result, 'wait');

        $this->assertSame(WorkflowSimulator::DID_WAITED, $wait['did']);
        $this->assertNotNull($wait['resume_at'], 'A wait must report when it would end.');
        $this->assertFalse($wait['would_run']);

        $previewed = \Illuminate\Support\Carbon::parse($wait['resume_at']);

        // Two days from now, in the Business's own timezone, is what the real
        // executor computes — so the preview lands in the same place.
        $this->assertSame(
            \Illuminate\Support\Carbon::now('America/New_York')->addDays(2)->utc()->format('Y-m-d H:i'),
            $previewed->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_a_draft_that_was_never_published_can_be_simulated(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        $draft = $this->draftBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        // Nothing is compiled for a draft, which is exactly why the simulator
        // walks the compiler's plan rather than the node table.
        $this->assertSame(0, DB::table('automation_workflow_nodes')->where('version_id', $draft->id)->count());

        $result = $this->simulator()->simulate($draft, $contact);

        $this->assertNull($result['refused']);
        $this->assertSame('yes', $this->branchStep($result)['branch']);
        $this->assertSame(0, DB::table('automation_workflow_nodes')->count(), 'Simulating a draft must not compile it.');
        $this->assertSame(0, DB::table('automation_workflow_edges')->count());
    }

    public function test_simulating_twice_gives_the_same_answer(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->updateFieldStep($fields['COMPANY'], 'x'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        $first = $this->simulator()->simulate($version, $contact);
        $second = $this->simulator()->simulate($version, $contact->fresh());

        $this->assertSame($first['steps'], $second['steps'], 'The same inputs must produce the same path.');
        $this->assertSame($first['ended'], $second['ended']);
    }

    public function test_an_empty_branch_lane_ends_the_simulated_path(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Grace']);

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [],
            ),
        ]);

        $result = $this->simulator()->simulate($version, $contact);

        $this->assertSame('no', $this->branchStep($result)['branch']);
        $this->assertSame(['trigger', 'if_else'], $this->stepTypes($result['steps']));
        $this->assertSame(WorkflowSimulator::ENDED_PATH_END, $result['ended']);
    }

    // =================================================================
    // Tenancy, and references that fail closed
    // =================================================================

    public function test_a_contact_from_another_business_is_refused(): void
    {
        [$business, $group] = $this->tenantWithIdentity();
        [, $rivalBusiness] = $this->entitledTenant();
        $rivalGroup = $this->contactGroup($rivalBusiness, 'Rivals ' . uniqid());
        $foreign = $this->contact($rivalBusiness, $rivalGroup, '1202555' . random_int(1000, 9999));

        [, $version] = $this->publishBody($business, $group, [$this->recordedStep('hi'), $this->endStep()]);

        $result = $this->simulator()->simulate($version, $foreign);

        $this->assertSame(WorkflowSimulator::REFUSED_FOREIGN_CONTACT, $result['refused']);
        $this->assertSame([], $result['steps'], 'A refused simulation walks nothing at all.');
    }

    public function test_a_contact_with_no_business_is_refused(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();
        [, $version] = $this->publishBody($business, $group, [$this->recordedStep('hi'), $this->endStep()]);

        DB::table('contacts')->where('id', $contact->id)->update(['business_id' => null]);

        $result = $this->simulator()->simulate($version, $contact->fresh());

        $this->assertSame(WorkflowSimulator::REFUSED_FOREIGN_CONTACT, $result['refused']);
        $this->assertSame([], $result['steps']);
    }

    public function test_a_group_reference_from_another_business_fails_closed(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();
        [, $rivalBusiness] = $this->entitledTenant();
        $rivalGroup = $this->contactGroup($rivalBusiness, 'Rivals ' . uniqid());

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.in_group', 'equals', $group->id)],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        // The reference is repointed at another Business's group AFTER publish,
        // which is the case the compiler's publish-time check cannot cover.
        $this->repointCondition($version, 'operand', $rivalGroup->id);

        $result = $this->simulator()->simulate($version->fresh(), $contact);

        $this->assertSame('no', $this->branchStep($result)['branch'], 'A foreign group must not be readable.');
    }

    public function test_a_custom_field_from_another_business_fails_closed(): void
    {
        [$business, $group, $contact, $fields] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);
        [, $rivalBusiness] = $this->entitledTenant();
        $rivalGroup = $this->contactGroup($rivalBusiness, 'Rivals ' . uniqid());
        $rivalField = $this->textField($rivalGroup, 'RIVAL_NOTE');

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.custom_field:' . $fields['FIRST_NAME']->id, 'is_not_empty')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        $this->repointCondition($version, 'subject', 'contact.custom_field:' . $rivalField->id);

        $result = $this->simulator()->simulate($version->fresh(), $contact);

        $this->assertSame('no', $this->branchStep($result)['branch'], "Another Business's field must not be readable.");
    }

    public function test_an_unknown_subject_is_false_rather_than_an_error(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);

        [, $version] = $this->publishBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        // A subject no registry knows — the shape a later slice's condition, or a
        // tampered definition, would have.
        $this->repointCondition($version, 'subject', 'contact.invented_by_nobody');

        $result = $this->simulator()->simulate($version->fresh(), $contact);

        $this->assertSame('no', $this->branchStep($result)['branch']);
        $this->assertNull($result['refused'], 'An unreadable condition is a No, not a crash.');
    }

    public function test_a_document_with_no_trigger_is_refused_not_guessed(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity();
        $draft = $this->draftBody($business, $group, [$this->endStep()]);

        DB::table('automation_workflow_versions')->where('id', $draft->id)->update([
            'definition' => json_encode(['schema_version' => 1]),
        ]);

        $result = $this->simulator()->simulate($draft->fresh(), $contact);

        $this->assertSame(WorkflowSimulator::REFUSED_UNWALKABLE, $result['refused']);
        $this->assertSame([], $result['steps']);
    }

    public function test_a_drafts_validation_errors_are_reported_without_blocking_the_path(): void
    {
        [$business, $group, $contact] = $this->tenantWithIdentity(['FIRST_NAME' => 'Ada']);
        [, $rivalBusiness] = $this->entitledTenant();
        $rivalGroup = $this->contactGroup($rivalBusiness, 'Rivals ' . uniqid());

        $draft = $this->draftBody($business, $group, [
            $this->ifElseStep(
                [$this->condition('contact.in_group', 'equals', $rivalGroup->id)],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ]);

        $result = $this->simulator()->simulate($draft, $contact);

        // Two things at once: the draft would not publish, AND the tester still
        // gets to see where this contact would go.
        $this->assertNotSame([], $result['validation'], 'A cross-Business reference must be reported.');
        $this->assertNull($result['refused']);
        $this->assertSame('no', $this->branchStep($result)['branch']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $result */
    private function branchStep(array $result): array
    {
        return $this->stepOfType($result, 'if_else');
    }

    /** @param array<string, mixed> $result */
    private function stepOfType(array $result, string $type): array
    {
        foreach ($result['steps'] as $step) {
            if ($step['type'] === $type) {
                return $step;
            }
        }

        $this->fail('The simulated path contains no ' . $type . ' step.');
    }

    /**
     * The message body of the first would-send step on the simulated path.
     *
     * Which lane the walk took is visible in the step keys, and the body is the
     * readable way to say which one — so this looks the key up in the compiled
     * graph rather than asserting on a uuid.
     */
    private function bodyOfFirstSms(array $result): ?string
    {
        foreach ($result['steps'] as $step) {
            if ($step['type'] === 'send_sms') {
                return $this->bodyForKey($step['key']);
            }
        }

        return null;
    }

    private function bodyForKey(string $key): ?string
    {
        $row = DB::table('automation_workflow_nodes')->where('node_key', $key)->first();

        if ($row === null) {
            return null;
        }

        $config = json_decode((string) $row->config, true);

        return is_array($config) ? ($config['body'] ?? null) : null;
    }

    /** An update-contact-field step writing a literal value. */
    private function updateFieldStep(ContactGroupFields $field, string $value): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'update_contact_field',
            'config' => ['field_id' => $field->id, 'value' => $value],
        ];
    }

    /** An internal-notification step. */
    private function notifyStep(string $message = 'Someone reached this step'): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'internal_notification',
            'config' => ['message' => $message],
        ];
    }

    /**
     * Rewrite one key of the published if/else node's first condition.
     *
     * Done directly against the compiled row on purpose: this is the state a
     * reference that MOVED after publish leaves behind, and it is the only way
     * to reach the runtime's re-derivation — the compiler would refuse to
     * publish it.
     */
    private function repointCondition(AutomationWorkflowVersion $version, string $key, mixed $value): void
    {
        $row = DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)
            ->where('node_type', 'if_else')
            ->first();

        if ($row !== null) {
            $config = json_decode((string) $row->config, true);
            $config['conditions'][0][$key] = $value;

            DB::table('automation_workflow_nodes')->where('id', $row->id)
                ->update(['config' => json_encode($config)]);
        }

        // The simulator reads the DOCUMENT, so the version's definition is the
        // copy that actually matters; the compiled row is updated too so this
        // helper describes one consistent state rather than two.
        $definition = json_decode((string) DB::table('automation_workflow_versions')
            ->where('id', $version->id)->value('definition'), true);

        $this->repointInDefinition($definition['root'], $key, $value);

        DB::table('automation_workflow_versions')->where('id', $version->id)
            ->update(['definition' => json_encode($definition)]);
    }

    private function repointInDefinition(array &$node, string $key, mixed $value): bool
    {
        if (($node['type'] ?? null) === 'if_else') {
            $node['config']['conditions'][0][$key] = $value;

            return true;
        }

        foreach (['next', 'yes', 'no'] as $lane) {
            foreach ($node[$lane] ?? [] as $index => $_) {
                if ($this->repointInDefinition($node[$lane][$index], $key, $value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
