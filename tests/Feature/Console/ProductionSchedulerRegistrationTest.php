<?php

namespace Tests\Feature\Console;

use App\Console\Kernel;
use App\Jobs\GoogleAds\SweepGoogleAdsSyncs;
use App\Jobs\MetaAds\SweepMetaAdsSyncs;
use App\Jobs\Seo\ProcessSeoRankChecks;
use App\Jobs\Seo\ScheduleSeoRankChecks;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

/**
 * V1 deployment readiness — a built command or job that nothing schedules is
 * permanently unreachable in production (this already happened once:
 * `usage:spending-threshold-alerts`). This pins the production scheduler's
 * contents so a sweep cannot silently drop out of, or never make it into,
 * App\Console\Kernel::schedule().
 *
 * It is a registration check, not a behaviour test: each command's own test
 * owns what it does. No database is touched.
 */
class ProductionSchedulerRegistrationTest extends TestCase
{
    /** Artisan commands that must be scheduled in production. */
    private const COMMANDS = [
        'queue:work --queue=automation,default,batch',
        'automation:run',
        'automation:workflows-recover-stalled',
        'automation:workflows-resume-due',
        'automation:workflows-date-sweep',
        'platform-automation:sweep',
        'platform-announcements:sweep',
        'calendar:dispatch-due-reminders',
        'calendar:sync-external-connections',
        'outreach:dispatch-due-followups',
        'seo:rank-sync-locations',
        'documents:expire-due',
        'documents:dispatch-due-reminders',
        'documents:dispatch-balance-requests',
        'documents:reconcile-stale-payments',
        'platform-subscriptions:apply-due-plan-changes',
        'agency-subscriptions:apply-due-plan-changes',
        'workspaces:advance-account-lifecycle',
        'usage:spending-threshold-alerts',
        'opportunity:sweep-expired-snoozes',
        'opportunity:dispatch-business-advisor',
        'growth:evaluate',
        'coo:dispatch-insight-reviews',
        'campaign:recurring',
        'campaign:scheduled',
        'sms:schedule-api-message',
        'subscription:check',
    ];

    /** Queued jobs that must be scheduled in production. */
    private const JOBS = [
        ScheduleSeoRankChecks::class,
        ProcessSeoRankChecks::class,
        SweepGoogleAdsSyncs::class,
        SweepMetaAdsSyncs::class,
        \App\Jobs\GoogleBusinessProfile\SweepGoogleBusinessProfileRefreshes::class,
        \App\Jobs\Messaging\RefreshPendingMessagingRegistrations::class,
        \App\Jobs\Messaging\RefreshPendingCampaignAssignments::class,
        \App\Jobs\Usage\ReconcileProviderPendingState::class,
        \App\Jobs\Usage\ExpireStaleUsageReservations::class,
        \App\Jobs\Ai\ExpireStaleAiReservations::class,
    ];

    /** @return array<int, \Illuminate\Console\Scheduling\Event> */
    private function events(): array
    {
        $kernel = app(Kernel::class);
        $schedule = app(Schedule::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    public function test_every_production_command_is_scheduled(): void
    {
        $registered = array_map(fn ($event) => (string) $event->command, $this->events());

        foreach (self::COMMANDS as $command) {
            $found = false;
            foreach ($registered as $line) {
                if (str_contains($line, $command)) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, "[{$command}] is not registered in App\\Console\\Kernel::schedule().");
        }
    }

    public function test_every_production_job_is_scheduled(): void
    {
        $registered = array_map(fn ($event) => (string) $event->description . '|' . $event->getSummaryForDisplay(), $this->events());

        foreach (self::JOBS as $job) {
            $found = false;
            foreach ($registered as $line) {
                if (str_contains($line, $job)) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, "[{$job}] is not registered in App\\Console\\Kernel::schedule().");
        }
    }

    public function test_the_spending_threshold_alert_is_hourly_and_does_not_overlap(): void
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->command, 'usage:spending-threshold-alerts')) {
                $this->assertSame('0 * * * *', $event->expression);
                $this->assertTrue($event->withoutOverlapping);

                return;
            }
        }

        $this->fail('usage:spending-threshold-alerts is not scheduled.');
    }
}
