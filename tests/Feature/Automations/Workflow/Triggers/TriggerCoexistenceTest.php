<?php

namespace Tests\Feature\Automations\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Jobs\AutomationJob;
use App\Library\Automation\Workflow\Executors\EndNodeExecutor;
use App\Library\Automation\Workflow\Executors\InternalNotificationNodeExecutor;
use App\Library\Automation\Workflow\Executors\SendSmsNodeExecutor;
use App\Library\Automation\Workflow\Executors\TriggerNodeExecutor;
use App\Library\Automation\Workflow\Executors\UpdateContactFieldNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use App\Models\AutomationEnrollment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Automations V2-C — what must still be true once the neighbouring slices land.
 *
 * Three registrations now share one service provider and one scheduler, and a
 * registry keyed by type REPLACES rather than adds when a lane is careless. So
 * the container contents and the scheduler inventory are asserted here by
 * identity, not assumed from a clean merge. The third proof is the one that is
 * easy to forget: a trigger source must do nothing at all when nobody triggered
 * anything — opening Business Home must not touch the workflow engine.
 */
class TriggerCoexistenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    /** The tables only the v2 engine owns. B4's `automations` is not one of them. */
    private const V2_TABLES = [
        'automation_workflows',
        'automation_workflow_versions',
        'automation_workflow_nodes',
        'automation_workflow_edges',
        'automation_enrollments',
        'automation_step_runs',
    ];

    // -----------------------------------------------------------------
    // The two registries
    // -----------------------------------------------------------------

    /**
     * Executors and trigger sources are separate registries, and neither lane's
     * registration may displace the other's. Checked by identity because a
     * wrong-keyed register() would still leave a registry that looks populated.
     */
    public function test_the_executor_and_trigger_source_registries_coexist(): void
    {
        $executors = app(NodeExecutorRegistry::class);
        $sources = app(TriggerSourceRegistry::class);

        $expectedExecutors = [
            WorkflowNodeType::Trigger->value => TriggerNodeExecutor::class,
            WorkflowNodeType::End->value => EndNodeExecutor::class,
            WorkflowNodeType::SendSms->value => SendSmsNodeExecutor::class,
            WorkflowNodeType::UpdateContactField->value => UpdateContactFieldNodeExecutor::class,
            WorkflowNodeType::InternalNotification->value => InternalNotificationNodeExecutor::class,
        ];

        foreach ($expectedExecutors as $type => $class) {
            $this->assertInstanceOf(
                $class,
                $executors->for(WorkflowNodeType::from($type)),
                $type . ' must still resolve its executor after this slice is added.',
            );
        }

        $expectedSources = [
            WorkflowTriggerType::ContactCreated->value => ContactCreatedTriggerSource::class,
            WorkflowTriggerType::ContactDateReached->value => DateReachedTriggerSource::class,
            WorkflowTriggerType::ManualEnrollment->value => ManualEnrollmentTriggerSource::class,
        ];

        foreach ($expectedSources as $type => $class) {
            $this->assertInstanceOf(
                $class,
                $sources->for(WorkflowTriggerType::from($type)),
                $type . ' must still resolve its trigger source after the executors are added.',
            );
        }

        $this->assertEqualsCanonicalizing(array_keys($expectedExecutors), $executors->registeredTypes());
        $this->assertSame(array_keys($expectedSources), $sources->registeredTypes());

        // Both are singletons, so what a lane registers at boot is what the
        // runtime and the triggers later resolve.
        $this->assertSame($executors, app(NodeExecutorRegistry::class));
        $this->assertSame($sources, app(TriggerSourceRegistry::class));
    }

    /**
     * Wait and If/Else are Lane D's, and their ABSENCE is load-bearing: the
     * advancer holds a step with no executor instead of skipping it, which is
     * what keeps a journey intact until that slice ships.
     */
    public function test_this_slice_registers_no_executor_it_does_not_own(): void
    {
        $executors = app(NodeExecutorRegistry::class);

        $this->assertFalse($executors->has(WorkflowNodeType::Wait));
        $this->assertFalse($executors->has(WorkflowNodeType::IfElse));
    }

    // -----------------------------------------------------------------
    // The scheduler
    // -----------------------------------------------------------------

    /**
     * A merge resolution in Kernel::schedule() can silently drop a line, and
     * nothing else in the suite would notice: a command that stops being
     * scheduled simply never runs. So every entry is inventoried by name.
     */
    public function test_the_scheduler_keeps_every_entry_and_adds_the_date_sweep(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->command ?: $event->description))
            ->values();

        $required = [
            // The queue worker every other scheduled job depends on.
            'queue:work --queue=automation,default,batch',
            // Legacy messaging and platform upkeep.
            'campaign:recurring',
            'campaign:scheduled',
            'sms:schedule-api-message',
            'subscription:check',
            'messaging:purge-webhook-rejections',
            'dashboard:warm',
            'keywords:check',
            'numbers:check',
            'senderid:check',
            'user:preferences',
            'app:clean-database',
            // B4 automations — still live until V2-G retires them.
            'automation:run',
            // V2 runtime recovery (V2-A) and this slice's sweep.
            'automation:workflows-recover-stalled',
            'automation:workflows-date-sweep',
            // COO / opportunity engine.
            'opportunity:sweep-expired-snoozes',
            'opportunity:dispatch-business-advisor',
            // Usage, slots and Google Business Profile background jobs.
            'PurgeExpiredWebhookPayloads',
            'ReconcileProviderPendingState',
            'RetryStuckPaymentProviderEvents',
            'InitiateSlotAgreementRenewal',
            'FinalizeSlotAgreementCancellation',
            'ReconcileSlotAgreementAllocation',
            'ExpireStaleUsageReservations',
            'PurgeExpiredGoogleBusinessProfileMirrors',
            'SweepGoogleBusinessProfileRefreshes',
        ];

        foreach ($required as $entry) {
            $this->assertTrue(
                $scheduled->contains(fn (string $command) => str_contains($command, $entry)),
                $entry . ' must still be scheduled.',
            );
        }

        // And the sweep exactly once, on the cadence B4's own sweep uses.
        $sweeps = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'automation:workflows-date-sweep'));

        $this->assertCount(1, $sweeps, 'Two sweep lines would be two ticks, not a faster one.');
        $this->assertSame('*/5 * * * *', $sweeps->first()->expression);
    }

    // -----------------------------------------------------------------
    // H-3 Business Home
    // -----------------------------------------------------------------

    /**
     * Opening Business Home is a read. It must not enroll anybody, must not
     * queue trigger work, and must not even ASK the workflow tables anything —
     * H-3's query budget is tight, and a trigger source that woke up on a page
     * load would be both a wrong behaviour and a cost.
     *
     * B4's `automations` table is deliberately not in this list: Home legitimately
     * reads B4 automation KPIs, and that is existing behaviour this slice leaves
     * alone.
     */
    public function test_opening_business_home_does_no_v2_trigger_work(): void
    {
        Queue::fake();
        $this->freezeClock();

        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Harbour Lane Studios', 'Harbour Lane');
        $this->authenticateAs($customer);

        Cache::flush();

        $touched = [];
        DB::listen(function ($query) use (&$touched): void {
            foreach (self::V2_TABLES as $table) {
                if (str_contains($query->sql, $table)) {
                    $touched[] = $table;
                }
            }
        });

        $this->get(route('user.home'))->assertOk();

        $this->assertSame([], array_unique($touched), 'Business Home must not query the workflow engine.');

        Queue::assertNotPushed(EnrollWorkflowContact::class);
        Queue::assertNotPushed(AdvanceWorkflowEnrollment::class);
        Queue::assertNotPushed(AutomationJob::class);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    /** And the page still renders its H-3 performance band while that is true. */
    public function test_business_home_still_renders_its_performance_band(): void
    {
        $this->freezeClock();

        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Harbour Lane Studios', 'Harbour Lane');
        $this->authenticateAs($customer);

        Cache::flush();
        $snapshot = $this->dashboardFor($customer->user);

        $this->assertSame(\App\Library\Dashboard\DashboardSnapshot::KIND_BUSINESS, $snapshot->kind);
        $this->assertIsArray(
            $snapshot->band(\App\Library\Dashboard\DashboardSnapshot::BAND_HEADLINES),
            'H-3s performance band must survive this slice.',
        );
    }
}
