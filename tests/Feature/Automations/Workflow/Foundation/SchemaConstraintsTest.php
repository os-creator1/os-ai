<?php

namespace Tests\Feature\Automations\Workflow\Foundation;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * Automations V2 V2-0 — T-WF-2, T-WF-31, T-WF-32, and the claim indexes.
 *
 * Everything here is a DATABASE guarantee rather than an application one, and
 * each is asserted by trying to violate it with raw SQL — bypassing every model,
 * service and validator. That is the point: if a future slice, a data repair
 * script or a migration tries the same thing, the database must still refuse.
 * A guarantee that only holds when the application is well-behaved is not a
 * guarantee, and the Slice 3 review found exactly that class of mistake.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    /**
     * T-WF-31 — the back-reference is composite, points at (id, workflow_id), and
     * restricts. All four properties are load-bearing, so all four are asserted.
     */
    public function test_the_back_reference_is_a_restricting_composite_foreign_key(): void
    {
        $columns = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('CONSTRAINT_NAME', 'aw_published_version_foreign')
            ->orderBy('ORDINAL_POSITION')
            ->get(['COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);

        $this->assertCount(2, $columns, 'It must be composite, not a single column.');
        $this->assertSame(['published_version_id', 'id'], $columns->pluck('COLUMN_NAME')->all());
        $this->assertSame(
            ['id', 'workflow_id'],
            $columns->pluck('REFERENCED_COLUMN_NAME')->all(),
            'Referencing (id, workflow_id) is what stops a workflow pointing at another workflow\'s version.',
        );
        $this->assertSame(
            DB::connection()->getTablePrefix() . 'automation_workflow_versions',
            $columns->first()->REFERENCED_TABLE_NAME,
        );

        $this->assertSame('RESTRICT', $this->deleteRule('aw_published_version_foreign'));
        $this->assertSame(
            'RESTRICT',
            $this->deleteRule('awv_workflow_foreign'),
            'Both sides of the cycle must restrict, so neither can cascade into the other.',
        );
    }

    /**
     * T-WF-32 — `published_version_id` accepts its own version and NULL, and
     * refuses both a nonexistent version and another workflow's real one.
     */
    public function test_published_version_id_can_never_point_outside_its_own_workflow(): void
    {
        [$workflowA, $versionA] = $this->seedWorkflowWithDraft('A');
        [$workflowB, $versionB] = $this->seedWorkflowWithDraft('B');

        DB::table('automation_workflows')->where('id', $workflowA->id)
            ->update(['published_version_id' => $versionA->id]);
        $this->assertSame($versionA->id, (int) $workflowA->fresh()->published_version_id);

        DB::table('automation_workflows')->where('id', $workflowA->id)
            ->update(['published_version_id' => null]);
        $this->assertNull($workflowA->fresh()->published_version_id);

        $this->assertRefused(
            fn () => DB::table('automation_workflows')->where('id', $workflowA->id)
                ->update(['published_version_id' => 9999999]),
            'A nonexistent version id must be refused.',
        );

        $this->assertRefused(
            fn () => DB::table('automation_workflows')->where('id', $workflowA->id)
                ->update(['published_version_id' => $versionB->id]),
            "Another workflow's existing version must be refused — this is the case a single-column key would accept.",
        );

        unset($workflowB);
    }

    /** T-WF-32 — a referenced version is protected from deletion, never cascaded. */
    public function test_a_referenced_version_cannot_be_deleted(): void
    {
        [$workflow, $version] = $this->seedWorkflowWithDraft('pinned');

        DB::table('automation_workflows')->where('id', $workflow->id)
            ->update(['published_version_id' => $version->id]);

        $this->assertRefused(
            fn () => DB::table('automation_workflow_versions')->where('id', $version->id)->delete(),
            'Deleting a version a workflow points at must be refused rather than silently cascading.',
        );
    }

    /** T-WF-1 — at most one draft and at most one published version per workflow. */
    public function test_a_workflow_can_hold_only_one_draft_and_one_published_version(): void
    {
        [$workflow, $draft] = $this->seedWorkflowWithDraft('guards');

        $this->assertRefused(
            fn () => $this->insertVersion($workflow, 2, 'draft'),
            'A second draft must be refused by the draft guard.',
        );

        // Superseded rows are exempt: any number may coexist.
        $this->insertVersion($workflow, 3, 'superseded');
        $this->insertVersion($workflow, 4, 'superseded');
        $this->addToAssertionCount(1);

        DB::table('automation_workflow_versions')->where('id', $draft->id)->update(['state' => 'published']);

        $this->assertRefused(
            fn () => $this->insertVersion($workflow, 5, 'published'),
            'A second published version must be refused by the published guard.',
        );
    }

    /**
     * T-WF-2 — the two indexes that make the graph a tree: a step cannot have two
     * successors of one kind, and no step can have two parents (so two lanes can
     * never rejoin).
     */
    public function test_the_graph_cannot_be_given_two_successors_or_two_parents(): void
    {
        [, $version] = $this->seedWorkflowWithDraft('tree');

        $a = $this->insertNode($version, 'a', 'trigger');
        $b = $this->insertNode($version, 'b', 'send_sms');
        $c = $this->insertNode($version, 'c', 'send_sms');

        $this->insertEdge($version, $a, $b, 'next');

        $this->assertRefused(
            fn () => $this->insertEdge($version, $a, $c, 'next'),
            'A step may have at most one "next" successor.',
        );

        $this->assertRefused(
            fn () => $this->insertEdge($version, $c, $b, 'next'),
            'A step may have at most one parent — this is what forbids a merge.',
        );
    }

    /** T-WF-2 — an edge cannot join two nodes of different versions. */
    public function test_an_edge_cannot_cross_versions(): void
    {
        [$workflow, $versionOne] = $this->seedWorkflowWithDraft('cross');
        $versionTwo = $this->insertVersion($workflow, 9, 'superseded');

        $nodeInOne = $this->insertNode($versionOne, 'one', 'trigger');
        $nodeInTwo = $this->insertNode($versionTwo, 'two', 'send_sms');

        $this->assertRefused(
            fn () => $this->insertEdge($versionOne, $nodeInOne, $nodeInTwo, 'next'),
            'An edge must not be able to point into another version\'s graph.',
        );
    }

    /** The step-run claim: one row per (enrollment, node), DB-enforced. */
    public function test_a_node_can_run_at_most_once_per_enrollment(): void
    {
        [$enrollment, $node] = $this->seedEnrollment();

        $this->insertStepRun($enrollment, $node);

        $this->assertRefused(
            fn () => $this->insertStepRun($enrollment, $node),
            'A second step run for the same enrollment and node must be refused — this is the at-most-once guarantee.',
        );
    }

    /** The enrollment claim, and the concurrent-occupancy guard. */
    public function test_one_contact_cannot_occupy_one_workflow_twice(): void
    {
        [$enrollment] = $this->seedEnrollment();

        // Same key: the durable idempotency claim.
        $this->assertRefused(
            fn () => $this->insertEnrollment(
                $enrollment->workflow_id,
                $enrollment->version_id,
                $enrollment->business_id,
                $enrollment->contact_id,
                $enrollment->enrollment_key,
            ),
            'A duplicate enrollment key must be refused.',
        );

        // Different key, same contact, still active: refused by the guard, which
        // holds whatever the enrollment policy says.
        $this->assertRefused(
            fn () => $this->insertEnrollment(
                $enrollment->workflow_id,
                $enrollment->version_id,
                $enrollment->business_id,
                $enrollment->contact_id,
                $enrollment->enrollment_key . ':other',
            ),
            'A second concurrent enrollment of the same contact must be refused by the active-contact guard.',
        );

        // Once terminal, the guard releases and a legitimate re-enrollment works.
        DB::table('automation_enrollments')->where('id', $enrollment->id)
            ->update(['status' => 'completed', 'current_node_id' => null]);

        $this->insertEnrollment(
            $enrollment->workflow_id,
            $enrollment->version_id,
            $enrollment->business_id,
            $enrollment->contact_id,
            $enrollment->enrollment_key . ':next-year',
        );

        $this->assertSame(
            2,
            DB::table('automation_enrollments')->where('contact_id', $enrollment->contact_id)->count(),
            'A terminal enrollment must not block a later legitimate one.',
        );
    }

    /** An enrollment cannot pin a version belonging to a different workflow. */
    public function test_an_enrollment_cannot_pin_another_workflows_version(): void
    {
        [$workflowA, $versionA] = $this->seedWorkflowWithDraft('pinA');
        [, $versionB] = $this->seedWorkflowWithDraft('pinB');

        [$customer, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Pin ' . uniqid());
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));
        unset($customer);

        $this->assertRefused(
            fn () => $this->insertEnrollment(
                (int) $workflowA->id,
                (int) $versionB->id,
                (int) $workflowA->business_id,
                (int) $contact->id,
                'cross-workflow-' . uniqid(),
            ),
            "An enrollment must not be able to pin another workflow's version.",
        );

        unset($versionA);
    }

    // ---------------------------------------------------------------
    // Fixtures. Raw inserts on purpose: these tests must bypass every
    // application guard so that only the database is under test.
    // ---------------------------------------------------------------

    /** @return array{0: AutomationWorkflow, 1: AutomationWorkflowVersion} */
    private function seedWorkflowWithDraft(string $label): array
    {
        [, $business] = $this->entitledTenant();

        $workflow = AutomationWorkflow::create([
            'business_id' => $business->id,
            'name' => 'Workflow ' . $label,
            'status' => 'draft',
        ]);

        $draft = $this->insertVersion($workflow, 1, 'draft');

        return [$workflow, $draft];
    }

    private function insertVersion(AutomationWorkflow $workflow, int $number, string $state): AutomationWorkflowVersion
    {
        $id = DB::table('automation_workflow_versions')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'workflow_id' => $workflow->id,
            'business_id' => $workflow->business_id,
            'version_number' => $number,
            'state' => $state,
            'definition' => json_encode(['schema_version' => 1]),
            'definition_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AutomationWorkflowVersion::query()->findOrFail($id);
    }

    private function insertNode(AutomationWorkflowVersion $version, string $key, string $type): int
    {
        return (int) DB::table('automation_workflow_nodes')->insertGetId([
            'version_id' => $version->id,
            'business_id' => $version->business_id,
            'node_key' => $key . '-' . uniqid(),
            'node_type' => $type,
            'config' => json_encode([]),
            'depth' => 0,
            'created_at' => now(),
        ]);
    }

    private function insertEdge(AutomationWorkflowVersion $version, int $from, int $to, string $kind): void
    {
        DB::table('automation_workflow_edges')->insert([
            'version_id' => $version->id,
            'business_id' => $version->business_id,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_kind' => $kind,
            'created_at' => now(),
        ]);
    }

    /** @return array{0: object, 1: int} the enrollment row and its cursor node id */
    private function seedEnrollment(): array
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Enrol ' . uniqid());
        $contact = $this->contact($business, $group, '1202555' . random_int(1000, 9999));

        $workflow = AutomationWorkflow::create([
            'business_id' => $business->id,
            'name' => 'Enrolment workflow',
            'status' => 'published',
        ]);

        $version = $this->insertVersion($workflow, 1, 'published');
        $node = $this->insertNode($version, 'root', 'trigger');

        $id = $this->insertEnrollment(
            (int) $workflow->id,
            (int) $version->id,
            (int) $business->id,
            (int) $contact->id,
            'wf:' . $workflow->id . ':c:' . $contact->id,
            $node,
        );

        return [DB::table('automation_enrollments')->find($id), $node];
    }

    private function insertEnrollment(
        int $workflowId,
        int $versionId,
        int $businessId,
        int $contactId,
        string $key,
        ?int $currentNodeId = null,
    ): int {
        return (int) DB::table('automation_enrollments')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $businessId,
            'workflow_id' => $workflowId,
            'version_id' => $versionId,
            'contact_id' => $contactId,
            'status' => 'active',
            'current_node_id' => $currentNodeId,
            'trigger_type' => 'contact_created',
            'trigger_occurrence_key' => (string) $contactId,
            'enrollment_key' => $key,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStepRun(object $enrollment, int $nodeId): void
    {
        DB::table('automation_step_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $nodeId,
            'node_type' => 'trigger',
            'status' => 'started',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertRefused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function deleteRule(string $name): ?string
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->value('DELETE_RULE');
    }
}
