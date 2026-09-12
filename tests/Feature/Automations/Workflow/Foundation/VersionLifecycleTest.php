<?php

namespace Tests\Feature\Automations\Workflow\Foundation;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * Automations V2 V2-0 — T-WF-1, T-WF-5, T-WF-6, T-WF-7 and the draft rules.
 *
 * The promise this slice makes to a customer is: editing never disturbs what is
 * running, and publishing never changes a journey already underway. These tests
 * hold that promise to the letter — they publish, then edit, then publish again,
 * and check that the first version's compiled graph is byte-for-byte what it was
 * and that an enrollment started on it still points at it.
 */
class VersionLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    private function drafts(): WorkflowDraftService
    {
        return app(WorkflowDraftService::class);
    }

    private function publisher(): WorkflowPublisher
    {
        return app(WorkflowPublisher::class);
    }

    /** A publishable document: trigger → send → wait → if/else with both lanes. */
    private function fullDefinition(string $body = 'Welcome aboard'): array
    {
        return [
            'schema_version' => 1,
            'root' => [
                'key' => (string) Str::uuid(),
                'type' => 'trigger',
                'config' => [
                    'trigger_type' => 'contact_created',
                    'enrollment_policy' => 'once_ever',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ],
                'next' => [
                    [
                        'key' => (string) Str::uuid(),
                        'type' => 'send_sms',
                        'config' => ['body' => $body],
                    ],
                    [
                        'key' => (string) Str::uuid(),
                        'type' => 'wait',
                        'config' => ['mode' => 'duration', 'amount' => 2, 'unit' => 'days'],
                    ],
                    [
                        'key' => (string) Str::uuid(),
                        'type' => 'if_else',
                        'config' => [
                            'match' => 'all',
                            'conditions' => [
                                ['subject' => 'contact.subscribed', 'operator' => 'is_true'],
                            ],
                        ],
                        'yes' => [[
                            'key' => (string) Str::uuid(),
                            'type' => 'send_sms',
                            'config' => ['body' => 'Still with us?'],
                        ]],
                        'no' => [[
                            'key' => (string) Str::uuid(),
                            'type' => 'end',
                            'config' => [],
                        ]],
                    ],
                ],
            ],
        ];
    }

    private function newWorkflow(?Business $business = null, string $name = 'New lead follow-up'): array
    {
        if ($business === null) {
            [, $business] = $this->entitledTenant();
        }

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $business,
            $name,
            WorkflowTriggerType::ContactCreated,
        );

        return [$workflow, $business];
    }

    /** A new workflow gets its trigger's own default rule, marked as a default. */
    public function test_a_new_workflow_carries_the_triggers_default_enrollment_rule(): void
    {
        [, $business] = $this->entitledTenant();

        foreach ([
            [WorkflowTriggerType::ContactCreated, EnrollmentPolicy::OnceEver],
            [WorkflowTriggerType::ManualEnrollment, EnrollmentPolicy::OnceEver],
            [WorkflowTriggerType::ContactDateReached, EnrollmentPolicy::OncePerOccurrence],
        ] as [$trigger, $expected]) {
            $workflow = $this->drafts()->createWorkflowWithDraft($business, 'W ' . $trigger->value, $trigger);
            $config = $workflow->draftVersion()->definition['root']['config'];

            $this->assertSame($trigger->value, $config['trigger_type']);
            $this->assertSame(
                $expected->value,
                $config['enrollment_policy'],
                sprintf('%s must default to %s.', $trigger->value, $expected->value),
            );
            $this->assertSame(EnrollmentPolicySource::Default->value, $config['enrollment_policy_source']);
        }
    }

    /** T-WF-5 — publishing compiles the graph and promotes the draft, atomically. */
    public function test_publishing_compiles_the_graph_and_promotes_the_draft(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $this->fullDefinition(), $draft->definition_revision);

        $published = $this->publisher()->publish($workflow->fresh());

        $this->assertSame(WorkflowVersionState::Published, $published->state);
        $this->assertSame(6, $published->node_count, 'Six steps: trigger, send, wait, if/else and one step per lane.');
        $this->assertNotNull($published->definition_hash);
        $this->assertSame(EnrollmentPolicy::OnceEver, $published->enrollment_policy);

        $workflow = $workflow->fresh();
        $this->assertSame(WorkflowStatus::Published, $workflow->status);
        $this->assertSame((int) $published->id, (int) $workflow->published_version_id);

        // A tree: one fewer edge than nodes.
        $nodes = DB::table('automation_workflow_nodes')->where('version_id', $published->id)->count();
        $edges = DB::table('automation_workflow_edges')->where('version_id', $published->id)->count();
        $this->assertSame(6, $nodes);
        $this->assertSame(5, $edges);

        // The branch really produced one `yes` and one `no`.
        $kinds = DB::table('automation_workflow_edges')->where('version_id', $published->id)
            ->pluck('edge_kind')->countBy()->all();
        $this->assertSame(1, $kinds['yes'] ?? 0);
        $this->assertSame(1, $kinds['no'] ?? 0);
        $this->assertSame(3, $kinds['next'] ?? 0);

        // No draft remains once it has been promoted.
        $this->assertNull($workflow->draftVersion());
    }

    /**
     * T-WF-6, T-WF-7 — the heart of it. Edit and republish, then prove the first
     * version's graph is untouched and the enrollment that started on it is still
     * pinned to it.
     */
    public function test_editing_and_republishing_leaves_the_running_version_untouched(): void
    {
        [$workflow, $business] = $this->newWorkflow();
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $this->fullDefinition('Version one body'), $draft->definition_revision);
        $versionOne = $this->publisher()->publish($workflow->fresh());

        $graphBefore = $this->graphFingerprint($versionOne);

        // Somebody is mid-journey on version one.
        $group = $this->contactGroup($business, 'Pinned ' . uniqid());
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));
        $rootNodeId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $versionOne->id)->where('node_type', 'trigger')->value('id');

        $enrollmentId = DB::table('automation_enrollments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workflow_id' => $workflow->id,
            'version_id' => $versionOne->id,
            'contact_id' => $contact->id,
            'status' => 'active',
            'current_node_id' => $rootNodeId,
            'trigger_type' => 'contact_created',
            'trigger_occurrence_key' => (string) $contact->id,
            'enrollment_key' => 'wf:' . $workflow->id . ':c:' . $contact->id,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Now edit and publish a second version.
        $secondDraft = $this->drafts()->ensureDraft($workflow->fresh());
        $this->assertSame(
            'Version one body',
            $secondDraft->definition['root']['next'][0]['config']['body'],
            'A new draft starts as a copy of what is live, so editing begins from reality.',
        );

        $this->drafts()->autosave($secondDraft, $this->fullDefinition('Version two body'), $secondDraft->definition_revision);
        $versionTwo = $this->publisher()->publish($workflow->fresh());

        $this->assertNotSame((int) $versionOne->id, (int) $versionTwo->id);
        $this->assertSame(2, (int) $versionTwo->version_number);

        // Version one: superseded, but its graph is byte-for-byte unchanged.
        $versionOne = $versionOne->fresh();
        $this->assertSame(WorkflowVersionState::Superseded, $versionOne->state);
        $this->assertSame(
            $graphBefore,
            $this->graphFingerprint($versionOne),
            'A republish must never rewrite an earlier version\'s compiled graph.',
        );

        // The enrollment is still pinned to version one, and its cursor still
        // points into version one's graph.
        $enrollment = DB::table('automation_enrollments')->find($enrollmentId);
        $this->assertSame((int) $versionOne->id, (int) $enrollment->version_id, 'The pin must not move.');
        $this->assertSame(
            (int) $versionOne->id,
            (int) DB::table('automation_workflow_nodes')->where('id', $enrollment->current_node_id)->value('version_id'),
            'The cursor must still point into the version the journey started on.',
        );

        // And the workflow now serves version two to anybody new.
        $this->assertSame((int) $versionTwo->id, (int) $workflow->fresh()->published_version_id);
    }

    /** A draft can be edited freely while a published version keeps running. */
    public function test_a_draft_is_editable_without_touching_the_published_version(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $this->fullDefinition('Live copy'), $draft->definition_revision);
        $published = $this->publisher()->publish($workflow->fresh());

        $publishedSnapshot = $published->fresh()->only([
            'state', 'definition', 'definition_hash', 'node_count', 'enrollment_policy',
        ]);
        $graphBefore = $this->graphFingerprint($published);

        $newDraft = $this->drafts()->ensureDraft($workflow->fresh());

        // Three separate saves, as the builder's autosave would do.
        $revision = $newDraft->definition_revision;

        foreach (['Edit one', 'Edit two', 'Edit three'] as $body) {
            $newDraft = $this->drafts()->autosave($newDraft, $this->fullDefinition($body), $revision);
            $revision = $newDraft->definition_revision;
        }

        $this->assertSame(
            'Edit three',
            $newDraft->fresh()->definition['root']['next'][0]['config']['body'],
        );

        $this->assertSame(
            $publishedSnapshot,
            $published->fresh()->only(['state', 'definition', 'definition_hash', 'node_count', 'enrollment_policy']),
            'Editing a draft must change nothing at all about the published version.',
        );
        $this->assertSame($graphBefore, $this->graphFingerprint($published));
    }

    /** Autosave is guarded: a stale revision loses rather than clobbering. */
    public function test_a_stale_autosave_is_refused_and_changes_nothing(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();

        // The revision BOTH tabs loaded. Captured as an integer on purpose:
        // autosave() refreshes the model it is given, so a second tab modelled by
        // re-reading the same object would silently be holding the NEW revision
        // and would not be stale at all.
        $staleRevision = (int) $draft->definition_revision;

        $first = $this->drafts()->autosave($draft, $this->fullDefinition('Tab one'), $staleRevision);
        $this->assertSame($staleRevision + 1, (int) $first->definition_revision);

        // The second tab saves with the revision it last saw.
        try {
            $this->drafts()->autosave($draft, $this->fullDefinition('Tab two'), $staleRevision);
            $this->fail('A stale revision must be refused.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            'Tab one',
            $first->fresh()->definition['root']['next'][0]['config']['body'],
            'The loser of a revision race must not overwrite the winner.',
        );
    }

    /** A published version can never be edited, whatever the caller intends. */
    public function test_a_published_version_cannot_be_autosaved(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $this->fullDefinition(), $draft->definition_revision);
        $published = $this->publisher()->publish($workflow->fresh());

        try {
            $this->drafts()->autosave($published, $this->fullDefinition('Sneaky'), $published->definition_revision);
            $this->fail('A published version must never accept an edit.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    /** T-WF-5 — an invalid draft cannot publish, and nothing is left behind. */
    public function test_an_invalid_draft_cannot_publish_and_leaves_no_partial_graph(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();

        $broken = $this->fullDefinition();
        // An If/Else with a step after it would be a merge.
        $broken['root']['next'][2]['next'] = [[
            'key' => (string) Str::uuid(),
            'type' => 'send_sms',
            'config' => ['body' => 'After the branch'],
        ]];

        $this->drafts()->autosave($draft, $broken, $draft->definition_revision);

        try {
            $this->publisher()->publish($workflow->fresh());
            $this->fail('A document describing a merge must not publish.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame(0, DB::table('automation_workflow_nodes')->count(), 'No partial graph may survive a failed publish.');
        $this->assertSame(0, DB::table('automation_workflow_edges')->count());
        $this->assertNull($workflow->fresh()->published_version_id);
        $this->assertSame(WorkflowStatus::Draft, $workflow->fresh()->status);
        $this->assertSame(WorkflowVersionState::Draft, $draft->fresh()->state, 'The draft must stay a draft.');
    }

    /** Discarding a draft returns the customer to what is live. */
    public function test_discarding_a_draft_leaves_the_published_version_alone(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $this->fullDefinition('Live'), $draft->definition_revision);
        $published = $this->publisher()->publish($workflow->fresh());

        $newDraft = $this->drafts()->ensureDraft($workflow->fresh());
        $this->drafts()->autosave($newDraft, $this->fullDefinition('Abandoned'), $newDraft->definition_revision);

        $this->drafts()->discardDraft($workflow->fresh());

        $this->assertNull($workflow->fresh()->draftVersion());
        $this->assertSame(WorkflowVersionState::Published, $published->fresh()->state);
        $this->assertSame((int) $published->id, (int) $workflow->fresh()->published_version_id);
    }

    /** Changing the trigger carries an untouched default with it (§7.5). */
    public function test_changing_the_trigger_updates_an_untouched_default_but_not_a_chosen_one(): void
    {
        [, $business] = $this->entitledTenant();

        // Untouched default follows the trigger.
        $definition = $this->drafts()->starterDefinition(WorkflowTriggerType::ContactCreated);
        $changed = $this->drafts()->withTriggerChanged($definition, WorkflowTriggerType::ContactDateReached);

        $this->assertSame('contact_date_reached', $changed['root']['config']['trigger_type']);
        $this->assertSame(
            EnrollmentPolicy::OncePerOccurrence->value,
            $changed['root']['config']['enrollment_policy'],
            'An untouched default must follow the new trigger, so a birthday workflow runs every year.',
        );

        // A policy the customer chose is left alone for the builder to confirm.
        $chosen = $definition;
        $chosen['root']['config']['enrollment_policy'] = EnrollmentPolicy::OnceEver->value;
        $chosen['root']['config']['enrollment_policy_source'] = EnrollmentPolicySource::User->value;

        $afterChange = $this->drafts()->withTriggerChanged($chosen, WorkflowTriggerType::ContactDateReached);

        $this->assertSame(
            EnrollmentPolicy::OnceEver->value,
            $afterChange['root']['config']['enrollment_policy'],
            'A chosen policy must not be silently overwritten.',
        );
        $this->assertSame(
            EnrollmentPolicySource::User->value,
            $afterChange['root']['config']['enrollment_policy_source'],
        );

        unset($business);
    }

    /**
     * A stale default — the exact thing that would turn a yearly workflow into a
     * one-time one — cannot be published.
     */
    public function test_a_stale_default_policy_cannot_be_published(): void
    {
        [$workflow] = $this->newWorkflow();
        $draft = $workflow->draftVersion();

        $stale = $this->fullDefinition();
        // The trigger became a date trigger, but the old once_ever default stayed.
        $stale['root']['config']['trigger_type'] = 'contact_date_reached';
        $stale['root']['config']['enrollment_policy'] = 'once_ever';
        $stale['root']['config']['enrollment_policy_source'] = 'default';

        $this->drafts()->autosave($draft, $stale, $draft->definition_revision);

        try {
            $this->publisher()->publish($workflow->fresh());
            $this->fail('A stale default must not publish.');
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('previous trigger', $messages);
        }
    }

    /**
     * A fingerprint of every compiled row for a version, used to prove a later
     * publish changed nothing about it.
     */
    private function graphFingerprint(AutomationWorkflowVersion $version): string
    {
        $nodes = DB::table('automation_workflow_nodes')->where('version_id', $version->id)
            ->orderBy('id')->get(['id', 'node_key', 'node_type', 'config', 'depth'])->toJson();

        $edges = DB::table('automation_workflow_edges')->where('version_id', $version->id)
            ->orderBy('id')->get(['id', 'from_node_id', 'to_node_id', 'edge_kind'])->toJson();

        return hash('sha256', $nodes . '|' . $edges);
    }
}
