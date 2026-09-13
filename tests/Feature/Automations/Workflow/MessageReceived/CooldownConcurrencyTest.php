<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Models\AutomationEnrollment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-F §9.1 — the cooldown under concurrency.
 *
 * Two different messages from the same person, handled by two workers at the
 * same instant, must not both pass the cooldown and both enroll. A cooldown
 * that is only a read would allow exactly that, and it would look correct in
 * every single-worker test. So it is proven against a SECOND REAL DATABASE
 * SESSION with the repository's deterministic lock pattern — no sleeps.
 *
 * A second session needs committed rows, hence UsesFreshSchema rather than
 * RefreshDatabase's transaction.
 */
class CooldownConcurrencyTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;
    use BuildsWorkflows;
    use BuildsInboundFixtures;

    private const SECOND_SESSION = 'mysql_v2f_cooldown_second_session';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();

        Bus::fake([AdvanceWorkflowEnrollment::class]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_SESSION);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

        parent::tearDown();
    }

    private function secondSession(): ConnectionInterface
    {
        config(['database.connections.' . self::SECOND_SESSION => config('database.connections.' . config('database.default'))]);

        $session = DB::connection(self::SECOND_SESSION);
        $session->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $session;
    }

    /**
     * While another worker holds this contact inside its cooldown decision, the
     * source cannot even begin its own — it waits on the contact row.
     */
    public function test_a_second_worker_cannot_decide_while_the_first_holds_the_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155555001');
        $event = $this->legacyInbound($business, '14155555001');

        // A SHARED lock, deliberately. It conflicts with the source's explicit
        // `FOR UPDATE` and with nothing else the source does: the enrollment
        // insert's foreign-key check on this contact only needs a shared lock
        // itself, and would pass straight through. So if the source did not
        // take its own lock, this test would NOT block — it proves the lock,
        // rather than proving that some write happened to wait.
        $first = $this->secondSession();
        $first->beginTransaction();
        $first->select('SELECT id FROM ' . DB::getTablePrefix() . 'contacts WHERE id = ? FOR SHARE', [$contact->id]);

        // This worker must block on the lock, and time out rather than hang.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;

        try {
            app(MessageReceivedTriggerSource::class)->handleInboundMessage($event);
        } catch (QueryException $exception) {
            $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
        }

        $first->rollBack();

        $this->assertTrue($blocked, 'The cooldown decision must be serialized on the contact row.');
        $this->assertSame(0, $this->enrollmentsFor($workflow), 'A blocked worker enrolls nobody.');
    }

    /**
     * The first worker's enrollment commits; the second worker, released by
     * that commit, must now see it and stand down.
     */
    public function test_once_the_first_worker_commits_the_second_sees_the_cooldown(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155555002');

        $versionId = (int) $workflow->fresh()->published_version_id;
        $rootNodeId = (int) DB::table('automation_workflow_nodes')->where('version_id', $versionId)->where('node_type', 'trigger')->value('id');

        // Worker A, in its own session: take the contact, enroll off message
        // one, commit — exactly the critical section the source runs.
        $first = $this->secondSession();
        $first->beginTransaction();
        $first->select('SELECT id FROM ' . DB::getTablePrefix() . 'contacts WHERE id = ? FOR UPDATE', [$contact->id]);
        $first->table('automation_enrollments')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workflow_id' => $workflow->id,
            'version_id' => $versionId,
            'contact_id' => $contact->id,
            'status' => EnrollmentStatus::Completed->value,
            'current_node_id' => null,
            'trigger_type' => WorkflowTriggerType::MessageReceived->value,
            'trigger_occurrence_key' => 'report:first-worker',
            'enrollment_key' => \App\Enums\Automation\Workflow\EnrollmentPolicy::OncePerOccurrence
                ->enrollmentKey((int) $workflow->id, (int) $contact->id, 'report:first-worker'),
            'causation_depth' => 0,
            'step_count' => 0,
            'enrolled_at' => Carbon::now()->subSecond(),
            'completed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        $first->commit();

        // Worker B, message two: a different occurrence, decided after A.
        $result = app(MessageReceivedTriggerSource::class)->handleInboundMessage($this->legacyInbound($business, '14155555002'));

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_COOLDOWN] ?? 0);
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count());
    }
}
