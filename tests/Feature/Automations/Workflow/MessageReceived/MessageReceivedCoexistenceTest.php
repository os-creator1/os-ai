<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\AutomationJob;
use App\Library\Automation\Workflow\Executors\EndNodeExecutor;
use App\Library\Automation\Workflow\Executors\IfElseNodeExecutor;
use App\Library\Automation\Workflow\Executors\InternalNotificationNodeExecutor;
use App\Library\Automation\Workflow\Executors\SendSmsNodeExecutor;
use App\Library\Automation\Workflow\Executors\TriggerNodeExecutor;
use App\Library\Automation\Workflow\Executors\UpdateContactFieldNodeExecutor;
use App\Library\Automation\Workflow\Executors\WaitNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Automations V2-F — the engine after message received lands.
 *
 * Adding a fourth trigger and marking automation sends touches one shared
 * provider closure, the Reports model and the send executor. None of that may
 * displace a trigger source, an executor, or anything B4 still runs on.
 */
class MessageReceivedCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_trigger_sources_coexist(): void
    {
        $registry = app(TriggerSourceRegistry::class);

        $expected = [
            WorkflowTriggerType::ContactCreated->value => ContactCreatedTriggerSource::class,
            WorkflowTriggerType::ContactDateReached->value => DateReachedTriggerSource::class,
            WorkflowTriggerType::ManualEnrollment->value => ManualEnrollmentTriggerSource::class,
            WorkflowTriggerType::MessageReceived->value => MessageReceivedTriggerSource::class,
        ];

        foreach ($expected as $type => $class) {
            $this->assertInstanceOf($class, $registry->for(WorkflowTriggerType::from($type)), $type . ' must keep its source.');
            $this->assertTrue($registry->available(WorkflowTriggerType::from($type)));
            $this->assertTrue(WorkflowTriggerType::from($type)->isIngestableInThisSlice());
        }

        $this->assertSame(array_keys($expected), $registry->registeredTypes());
    }

    public function test_all_seven_executors_still_coexist(): void
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
            $this->assertInstanceOf($class, $registry->for(WorkflowNodeType::from($type)), $type . ' must keep its executor.');
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), $registry->registeredTypes());

        // The send executor still resolves through the container with its new
        // collaborator, rather than needing to be built by hand anywhere.
        $this->assertInstanceOf(SendSmsNodeExecutor::class, app(SendSmsNodeExecutor::class));
    }

    public function test_b4_keeps_its_own_report_mark_its_runtime_and_its_schedule(): void
    {
        // B4 marks its sends with `automation_id` and keeps doing so; the v2 mark
        // is a separate column, not a reuse of B4's.
        $this->assertTrue(Schema::hasColumn('reports', 'automation_id'));
        $this->assertTrue(Schema::hasColumn('reports', 'automation_step_run_id'));

        $foreignKeys = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name, REFERENCED_TABLE_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['reports'],
        ))->pluck('ref', 'name');

        $this->assertSame('automations', $foreignKeys['reports_automation_id_foreign'] ?? null, "B4's reference is untouched.");
        $this->assertSame('automation_step_runs', $foreignKeys['reports_automation_step_run_id_foreign'] ?? null);

        $this->assertTrue(class_exists(AutomationJob::class), 'B4 stays live until V2-G retires it.');

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);
        $this->assertTrue($scheduled->contains(fn (string $command) => str_contains($command, 'automation:run')));
    }

    public function test_the_legacy_inbox_broadcast_is_not_the_domain_event(): void
    {
        // CX §15.1: the websocket broadcast is never adapted into a domain event.
        $this->assertNotSame(\App\Events\MessageReceived::class, \App\Events\Conversation\InboundMessageReceived::class);
        $this->assertFalse(is_subclass_of(\App\Events\Conversation\InboundMessageReceived::class, \Illuminate\Contracts\Broadcasting\ShouldBroadcast::class));
        $this->assertFalse(is_subclass_of(\App\Events\Conversation\InboundMessageReceived::class, \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class));
    }
}
