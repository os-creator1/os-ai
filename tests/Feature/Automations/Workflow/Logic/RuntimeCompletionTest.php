<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\EndNodeExecutor;
use App\Library\Automation\Workflow\Executors\IfElseNodeExecutor;
use App\Library\Automation\Workflow\Executors\InternalNotificationNodeExecutor;
use App\Library\Automation\Workflow\Executors\SendSmsNodeExecutor;
use App\Library\Automation\Workflow\Executors\TriggerNodeExecutor;
use App\Library\Automation\Workflow\Executors\UpdateContactFieldNodeExecutor;
use App\Library\Automation\Workflow\Executors\WaitNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2-A — the state of the engine now that the logic runtime lands.
 *
 * Three things that are only true once every slice so far is in one tree, and
 * that nothing else asserts together:
 *
 *   THE REGISTRIES ARE COMPLETE AND SEPARATE. Seven node types, seven executors,
 *   three trigger sources. Both registries are keyed by type, so a careless
 *   registration replaces rather than adds, and the two families share one
 *   service provider closure.
 *
 *   THERE IS ONE WAKE OWNER. The contract's `WaitScheduler` responsibility is
 *   implemented by WorkflowWakeService, deliberately not by a class of that
 *   name, and there is no second wake service anywhere.
 *
 *   T-WF-13's if/else half. An interrupted If/Else is re-derived and advances —
 *   proved on an `if_else` node specifically, because "a pure step" in general
 *   is a weaker claim than the invariant the contract names.
 */
class RuntimeCompletionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    /**
     * The recording executor is registered per-test, NOT in setUp(), because two
     * tests here assert the registry's real contents — and registering a fake for
     * `send_sms` would replace the executor they are checking for.
     */
    private function withRecordingExecutor(): RecordingNodeExecutor
    {
        return $this->recordingExecutor();
    }

    // =================================================================
    // The two registries, complete and side by side
    // =================================================================

    public function test_every_declared_node_type_has_its_own_executor(): void
    {
        $registry = app(NodeExecutorRegistry::class);

        $expected = [
            WorkflowNodeType::Trigger->value => TriggerNodeExecutor::class,
            WorkflowNodeType::End->value => EndNodeExecutor::class,
            WorkflowNodeType::Wait->value => WaitNodeExecutor::class,
            WorkflowNodeType::IfElse->value => IfElseNodeExecutor::class,
            WorkflowNodeType::SendSms->value => SendSmsNodeExecutor::class,
            WorkflowNodeType::UpdateContactField->value => UpdateContactFieldNodeExecutor::class,
            WorkflowNodeType::InternalNotification->value => InternalNotificationNodeExecutor::class,
        ];

        foreach ($expected as $type => $class) {
            $executor = $registry->for(WorkflowNodeType::from($type));

            $this->assertInstanceOf($class, $executor, $type . ' must resolve its own executor.');
            $this->assertSame($type, $executor->handles()->value, 'An executor must be keyed by the type it handles.');
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), $registry->registeredTypes());

        // And the enum is the authority: no declared type may be unserved, and
        // no served type may be undeclared.
        $this->assertEqualsCanonicalizing(
            array_map(fn (WorkflowNodeType $type) => $type->value, WorkflowNodeType::cases()),
            $registry->registeredTypes(),
            'The registry and the node-type enum must agree exactly.',
        );
    }

    public function test_the_trigger_sources_still_number_exactly_three(): void
    {
        $sources = app(TriggerSourceRegistry::class);

        $expected = [
            WorkflowTriggerType::ContactCreated->value => ContactCreatedTriggerSource::class,
            WorkflowTriggerType::ContactDateReached->value => DateReachedTriggerSource::class,
            WorkflowTriggerType::ManualEnrollment->value => ManualEnrollmentTriggerSource::class,
        ];

        foreach ($expected as $type => $class) {
            $this->assertInstanceOf($class, $sources->for(WorkflowTriggerType::from($type)));
            $this->assertTrue($sources->available(WorkflowTriggerType::from($type)));
        }

        $this->assertSame(array_keys($expected), $sources->registeredTypes());

        // V2-F's is still absent, and reporting that honestly is what keeps the
        // validator's refusal to publish such a workflow correct.
        $this->assertNull($sources->for(WorkflowTriggerType::MessageReceived));
        $this->assertFalse($sources->available(WorkflowTriggerType::MessageReceived));
    }

    public function test_the_two_registries_are_independent_singletons(): void
    {
        $executors = app(NodeExecutorRegistry::class);
        $sources = app(TriggerSourceRegistry::class);

        $this->assertSame($executors, app(NodeExecutorRegistry::class));
        $this->assertSame($sources, app(TriggerSourceRegistry::class));
        $this->assertCount(7, $executors->registeredTypes());
        $this->assertCount(3, $sources->registeredTypes());
    }

    // =================================================================
    // One wake owner
    // =================================================================

    /**
     * The contract's wake-scheduler responsibility has exactly one
     * implementation, and it is not duplicated under the contract's older name.
     */
    public function test_there_is_one_wake_owner_and_no_duplicate_scheduler(): void
    {
        $this->assertTrue(class_exists(WorkflowWakeService::class));
        $this->assertTrue(method_exists(WorkflowWakeService::class, 'wakeDue'));

        foreach ([
            'App\Library\Automation\Workflow\Runtime\WaitScheduler',
            'App\Library\Automation\Workflow\WaitScheduler',
            'App\Library\Automation\Workflow\Runtime\WorkflowWaitScheduler',
        ] as $duplicate) {
            $this->assertFalse(
                class_exists($duplicate),
                $duplicate . ' must not exist: one wake owner, not two.',
            );
        }

        // The responsibility is split across three collaborators on purpose —
        // the instant is the executor's, the parking is the advancer's, the wake
        // is this service's — so no single "scheduler" class should reappear.
        $this->assertTrue(method_exists(WaitNodeExecutor::class, 'execute'));
        $this->assertTrue(method_exists(WorkflowAdvancer::class, 'advance'));
    }

    /**
     * And the authoritative contract now names the delivered owner, so the
     * document and the code cannot drift back apart.
     */
    public function test_the_contract_names_the_delivered_wake_owner(): void
    {
        $contract = file_get_contents(base_path('docs/automation/AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md'));

        // The delivered-components row is the part that has to be true of the
        // code. The old name survives elsewhere in the document only inside the
        // note that explains the reconciliation, which is the opposite of drift.
        $row = null;

        foreach (preg_split('/\R/', $contract) as $line) {
            if (str_starts_with($line, '| **V2-A Runtime engine**')) {
                $row = $line;

                break;
            }
        }

        $this->assertNotNull($row, 'The V2-A delivered-components row must exist.');
        $this->assertStringContainsString('WorkflowWakeService', $row, 'V2-A must deliver the class that exists.');
        $this->assertStringNotContainsString('WaitScheduler', $row, 'No component may be named after a class nothing implements.');
        $this->assertStringContainsString('WorkflowSimulator', $row);

        // And the reconciliation itself is recorded, so the next reader does not
        // have to rediscover why the name changed.
        $this->assertStringContainsString('durable wake-scheduler responsibility', $contract);
    }

    // =================================================================
    // T-WF-13 — an interrupted If/Else is re-derived and advances
    // =================================================================

    public function test_an_interrupted_if_else_is_re_derived_and_advances(): void
    {
        $this->withRecordingExecutor();

        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members ' . uniqid());
        $fields = $this->identityFields($group);
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));
        $this->setContactValues($contact, $fields, ['FIRST_NAME' => 'Ada']);

        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Interrupted branch', WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = [
            $this->ifElseStep(
                [$this->condition('contact.first_name', 'equals', 'Ada')],
                [$this->recordedStep('yes branch'), $this->endStep()],
                [$this->recordedStep('no branch'), $this->endStep()],
            ),
        ];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $version = app(WorkflowPublisher::class)->publish($workflow->fresh());

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact->fresh(), (string) $contact->id);

        // Park the journey ON the if/else node with a claim that was started and
        // never finished — a worker killed mid-evaluation.
        $ifElseNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)
            ->where('node_type', 'if_else')
            ->value('id');

        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'current_node_id' => $ifElseNodeId,
            'last_advanced_at' => Carbon::now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
            'enrolled_at' => Carbon::now()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES + 5),
        ]);

        DB::table('automation_step_runs')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $ifElseNodeId,
            'node_type' => 'if_else',
            'status' => StepRunStatus::Started->value,
            'started_at' => Carbon::now()->subHour(),
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $counts = app(WorkflowRecoveryService::class)->recoverStalled();
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);

        $this->assertSame(1, $counts['redispatched']);
        $this->assertSame(
            0,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->count(),
            'A branch decision is pure, so its abandoned claim is released rather than failed.',
        );
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);

        // Now it actually runs again, reaches the same decision, and continues
        // down that lane — the journey is intact, not ended.
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame('yes', $this->branchTakenFor($enrollment));
        $this->assertSame(
            1,
            DB::table('automation_step_runs')
                ->where('enrollment_id', $enrollment->id)
                ->where('node_type', 'if_else')
                ->count(),
            'Re-deriving a branch produces one step run, not two.',
        );
    }

    /**
     * Re-derivation is only safe because the decision is a function of stored
     * state: evaluating the same contact twice cannot produce two answers.
     */
    public function test_the_same_contact_evaluates_to_the_same_branch_every_time(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members ' . uniqid());
        $fields = $this->identityFields($group);
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));
        $this->setContactValues($contact, $fields, ['FIRST_NAME' => 'Ada']);

        $node = new \App\Models\AutomationWorkflowNode([
            'node_type' => WorkflowNodeType::IfElse->value,
            'config' => [
                'match' => 'all',
                'conditions' => [$this->condition('contact.first_name', 'equals', 'Ada')],
            ],
        ]);

        $enrollment = new AutomationEnrollment([
            'business_id' => $business->id,
            'contact_id' => $contact->id,
        ]);

        $executor = app(IfElseNodeExecutor::class);

        $first = $executor->execute($node, $enrollment, $business, $contact->fresh());
        $second = $executor->execute($node, $enrollment, $business, $contact->fresh());

        $this->assertSame('yes', $first->branchTaken?->value);
        $this->assertSame($first->branchTaken, $second->branchTaken);
    }
}
