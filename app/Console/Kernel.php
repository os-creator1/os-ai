<?php

    namespace App\Console;

    use App\Console\Commands\CheckKeywords;
    use App\Console\Commands\CheckPhoneNumbers;
    use App\Console\Commands\CheckSenderID;
    use App\Console\Commands\CheckSessionWhatSender;
    use App\Console\Commands\CheckSubscription;
    use App\Console\Commands\CheckUserPreferences;
    use App\Console\Commands\CleanDatabase;
    use App\Console\Commands\CleanUpJobMonitors;
    use App\Console\Commands\ClearCampaign;
    use App\Console\Commands\DiafaanDLR;
    use App\Console\Commands\RunAutomation;
    use App\Console\Commands\RunEveryTenSeconds;
    use App\Console\Commands\SendRecurringCampaign;
    use App\Console\Commands\SendScheduleAPIMessage;
    use App\Console\Commands\SMPPDLRReports;
    use App\Console\Commands\UpdateImartGroupDLR;
    use App\Console\Commands\VisionUpInboundMessage;
    use App\Console\Commands\WarmDashboardCache;
    use App\Jobs\GoogleBusinessProfile\PurgeExpiredGoogleBusinessProfileMirrors;
    use App\Jobs\GoogleBusinessProfile\SweepGoogleBusinessProfileRefreshes;
    use App\Jobs\Usage\ExpireStaleUsageReservations;
    use App\Jobs\Usage\FinalizeSlotAgreementCancellation;
    use App\Jobs\Usage\InitiateSlotAgreementRenewal;
    use App\Jobs\Usage\PurgeExpiredWebhookPayloads;
    use App\Jobs\Usage\ReconcileProviderPendingState;
    use App\Jobs\Usage\ReconcileSlotAgreementAllocation;
    use App\Jobs\Usage\RetryStuckPaymentProviderEvents;
    use Illuminate\Console\Scheduling\Schedule;
    use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

    class Kernel extends ConsoleKernel
    {
        /**
         * The Artisan commands provided by your application.
         *
         * @var array
         */
        protected $commands = [
            CheckSubscription::class,
            CheckKeywords::class,
            CheckPhoneNumbers::class,
            CheckSenderID::class,
            CheckUserPreferences::class,
            SendRecurringCampaign::class,
            VisionUpInboundMessage::class,
            UpdateImartGroupDLR::class,
            CheckSessionWhatSender::class,
            ClearCampaign::class,
            SendScheduleAPIMessage::class,
            RunAutomation::class,
            \App\Console\Commands\Automation\RecoverStalledWorkflowEnrollments::class,
            \App\Console\Commands\Automation\ResumeDueWorkflowEnrollments::class,
            SMPPDLRReports::class,
            RunEveryTenSeconds::class,
            CleanDatabase::class,
            DiafaanDLR::class,
            CleanUpJobMonitors::class,
            WarmDashboardCache::class,
        ];

        /**
         * Define the application's command schedule.
         *
         *
         * @return void
         */
        protected function schedule(Schedule $schedule)
        {
            if ( ! file_exists(storage_path('cronJobAvailable'))) {

                $installedCronFile = storage_path('cronJobAvailable');
                file_put_contents($installedCronFile, date('Y/m/d h:i:sa'));

            }

            $schedule->command('queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty')->everyMinute();

            $schedule->command('campaign:recurring')->everyMinute();
            $schedule->command('campaign:scheduled')->everyMinute();
            $schedule->command('sms:schedule-api-message')->everyMinute();
            $schedule->command('subscription:check')->hourly();
            // Customer Experience Slice 3 §4.2 — retention/disposal for the
            // bounded webhook rejection audit.
            $schedule->command('messaging:purge-webhook-rejections')->daily();
            //   $schedule->command('imartgroup:dlr')->hourly();
            $schedule->command('dashboard:warm')->hourly();
            $schedule->command('keywords:check')->daily();
            $schedule->command('numbers:check')->daily();
            $schedule->command('senderid:check')->daily();
            $schedule->command('user:preferences')->daily()->between('10:00', '18:00');
            $schedule->command('automation:run')->everyFiveMinutes();

            // Automations V2 §7.4 — the lost/interrupted-work safety net ONLY.
            // Resume re-dispatches held journeys immediately (§6.3) and never
            // waits for this sweep; it deliberately skips paused workflows.
            $schedule->command('automation:workflows-recover-stalled')->everyFiveMinutes();

            // Automations V2 §8.2/§8.3 — waking journeys whose wait has elapsed.
            // EVERY MINUTE, because one minute is the resolution the product
            // promises for a wait; five would make every "wait 5 minutes" land
            // up to ten minutes late. It selects `waiting` rows only, so it can
            // never contend with the recovery sweep above, which selects
            // `active` ones.
            $schedule->command('automation:workflows-resume-due')->everyMinute();

            // Automations V2-C — a date arriving is not an event anything
            // emits, so something has to look. Five minutes matches B4's own
            // cadence, and the command is bounded and idempotent by the
            // enrollment claim, so an overlapping tick enrolls nobody twice.
            $schedule->command('automation:workflows-date-sweep')->everyFiveMinutes();
            $schedule->command('app:clean-database')->monthly();
            // $schedule->command('jobs:cleanup-monitors')->everyThirtyMinutes();

            // Registered unconditionally (RFC-002 §33) — the command
            // itself owns opportunity.enabled no-op behavior, not the
            // scheduler.
            $schedule->command('opportunity:sweep-expired-snoozes')
                ->cron('*/' . $this->opportunitySnoozeSweepCronMinutes() . ' * * * *');

            // COO C-1 — the daily Business Advisor producer sweep. Registered
            // unconditionally for the same reason as the snooze sweep: the
            // command owns opportunity.enabled no-op behavior. One run a day,
            // bounded by its own --limit/--page, and idempotent per Business
            // per day, so overlap or a manual re-run duplicates nothing.
            $schedule->command('opportunity:dispatch-business-advisor', [
                '--limit=' . (int) config('opportunity.sweep_limit', 500),
                '--page=' . (int) config('opportunity.sweep_page', 100),
            ])->dailyAt('03:10')->withoutOverlapping();

            // RFC-005 Milestone 3 (Correction Round 1, item 110) —
            // without these, both jobs are permanently unreachable
            // (unlike ProcessPaymentProviderEvent/EvaluateBusinessAutoRecharge,
            // neither is dispatched by any event/controller/manager).
            $schedule->job(new PurgeExpiredWebhookPayloads())->hourly();
            $schedule->job(new ReconcileProviderPendingState())->everyFiveMinutes();
            $schedule->job(new RetryStuckPaymentProviderEvents())->everyFiveMinutes();

            // M4 contract §22 — locked exact intervals, not an
            // implementation-time choice.
            $schedule->job(new InitiateSlotAgreementRenewal())->everyFiveMinutes();
            $schedule->job(new FinalizeSlotAgreementCancellation())->everyFiveMinutes();
            $schedule->job(new ReconcileSlotAgreementAllocation())->hourly();

            // RFC-005 Job/Event Dispatch Completion Correction Contract
            // §3 — human-authorized cadence; without this the job is
            // built (M1) but permanently unreachable. release() is
            // idempotent/row-locked, so cadence affects only latency,
            // never domain semantics.
            $schedule->job(new ExpireStaleUsageReservations())->everyFiveMinutes();

            // Google Business Profile Slice A (contract §13.2, §24.2).
            //
            // The purge is HOURLY and is the enforcement half of Google's
            // 30-calendar-day cap on stored Content — it must run far more
            // often than the retention window it enforces, mirroring
            // PurgeExpiredWebhookPayloads above.
            //
            // The refresh sweep is DAILY and nothing faster: it only
            // dispatches per-binding jobs on a deterministic stagger, and
            // Google denies quota increases to applications showing "a
            // highly spiky request pattern rather than a smooth
            // distribution". Manual refresh remains the primary mechanism.
            $schedule->job(new PurgeExpiredGoogleBusinessProfileMirrors())->hourly();
            $schedule->job(new SweepGoogleBusinessProfileRefreshes())->daily();
        }

        /**
         * Normalizes opportunity.snooze_sweep_minutes into a safe cron
         * step value (RFC-002 §14, §33): an integer or digit-only string
         * from 1 through 59, otherwise the RFC's own documented default.
         */
        private function opportunitySnoozeSweepCronMinutes(): int
        {
            $configured = config('opportunity.snooze_sweep_minutes', 15);

            if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
                return 15;
            }

            $minutes = (int) $configured;

            if ($minutes < 1 || $minutes > 59) {
                return 15;
            }

            return $minutes;
        }

        /**
         * Register the commands for the application.
         *
         * @return void
         */
        protected function commands()
        {
            $this->load(__DIR__ . '/Commands');

            require base_path('routes/console.php');
        }

    }
