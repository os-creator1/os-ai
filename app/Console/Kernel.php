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
    use App\Jobs\Ai\ExpireStaleAiReservations;
    use App\Jobs\GoogleAds\SweepGoogleAdsSyncs;
    use App\Jobs\MetaAds\SweepMetaAdsSyncs;
    use App\Jobs\GoogleBusinessProfile\PurgeExpiredGoogleBusinessProfileMirrors;
    use App\Jobs\GoogleBusinessProfile\SweepGoogleBusinessProfileRefreshes;
    use App\Jobs\Messaging\RefreshPendingCampaignAssignments;
    use App\Jobs\Messaging\RefreshPendingMessagingRegistrations;
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
            \App\Console\Commands\Automation\SweepDateReachedWorkflows::class,
            SMPPDLRReports::class,
            RunEveryTenSeconds::class,
            CleanDatabase::class,
            DiafaanDLR::class,
            CleanUpJobMonitors::class,
            WarmDashboardCache::class,
            \App\Console\Commands\Outreach\DispatchDueOutreachFollowUps::class,
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
            // Contract 03 §7 (Slice 4) — the two time-based account
            // lifecycle sweeps: expired trials into Grace, elapsed Grace
            // into Locked. Hourly, matching subscription:check's own
            // billing-state cadence: an account should not sit an extra day
            // in a state its data already left. Both sweeps are idempotent,
            // so withoutOverlapping() is protection against a slow run
            // stacking on the next tick, not a correctness requirement.
            $schedule->command('workspaces:advance-account-lifecycle')->hourly()->withoutOverlapping();
            // Customer Experience Slice 3 §4.2 — retention/disposal for the
            // bounded webhook rejection audit.
            $schedule->command('messaging:purge-webhook-rejections')->daily();
            // Phone Numbers + A2P lane — messaging contract §13.2/§13.3's
            // renewal/advance-warning/release-notice sweep. Never decides
            // to release a number itself; see NumberLifecycleManager::
            // recordReleaseDecision()'s own explicit, audited, admin-only
            // action, which itself never confirms a real carrier release.
            $schedule->command('messaging:sweep-number-renewals')->daily()->withoutOverlapping();
            // Implementation Contract 15 §12.F — an implementation-time
            // cadence decision (not pinned by any authoritative document):
            // frequent enough that a webhook-missed change still converges
            // reasonably quickly, bounded so this never becomes the
            // dominant source of provider request volume.
            $schedule->command('calendar:sync-external-connections')->everyFifteenMinutes()->withoutOverlapping();
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

            // Agency Outreach V1 (contract §11) — re-dispatches follow-ups that are due and were
            // neither sent nor cancelled, so a lost delayed job still gets its one nudge. Idempotent
            // by the ledger key claimed under the member lock.
            $schedule->command('outreach:dispatch-due-followups')->everyFiveMinutes();

            // Booking Notifications V1 — queues due booking confirmations and reminders.
            // Every minute: a reminder's resolution is the minute. Bounded by --limit and
            // idempotent by the appointment_notifications ledger, so an overlapping or
            // repeated tick sends nothing twice.
            $schedule->command('calendar:dispatch-due-reminders')->everyMinute();
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

            // Growth Center — the daily full evaluation, after the Business
            // Advisor sweep so profile gaps are settled first. Same contract:
            // the command owns the engine-enabled no-op, is bounded by
            // --limit/--page, and is idempotent per Business per day.
            $schedule->command('growth:evaluate', [
                '--limit=' . (int) config('growth.evaluation.sweep_limit', 500),
                '--page=' . (int) config('growth.evaluation.sweep_page', 100),
            ])->dailyAt('03:40')->withoutOverlapping();

            // Implementation Contract 17 §12.F — the three document sweeps.
            // Registered unconditionally for exactly the reason the snooze
            // sweep above is: each command owns the documents.enabled no-op,
            // so the scheduler never has to know whether Payments & Contracts
            // is turned on. Each is bounded by its own config limit and is
            // idempotent by durable markers or provider truth, so an
            // overlapping tick expires, reminds or reconciles nothing twice.
            $schedule->command('documents:expire-due')->everyFifteenMinutes();
            $schedule->command('documents:dispatch-due-reminders')->hourly();
            // Contract 17B §3/§7 — the one send-once balance payment request
            // (fresh secure link) at the balance item's frozen due_at. Same
            // flag-gated no-op in the command; durable per-item claim markers
            // dedupe, withoutOverlapping only avoids two ticks doing the work.
            $schedule->command('documents:dispatch-balance-requests')->hourly()->withoutOverlapping();
            $schedule->command('documents:reconcile-stale-payments')->everyFiveMinutes();

            // Implementation Contract 21 §10.2 — a downgrade takes effect at
            // the END of the period the customer already paid for, so
            // something has to notice the boundary arrived. Hourly matches
            // workspaces:advance-account-lifecycle's own cadence: both are
            // time-based commercial sweeps, and an account should not sit an
            // extra day on a tier it cancelled. Bounded and idempotent, so an
            // overlapping tick applies nothing twice.
            $schedule->command('platform-subscriptions:apply-due-plan-changes')->hourly();

            // Customer Experience Slice 5 §12.2 — "alerts before thresholds".
            // The command was built (and tested) but nothing ever ran it, so
            // no billing contact was ever warned before a spending limit was
            // reached. The once-per-period marker lives in UsageWalletManager,
            // so an overlapping or repeated tick notifies nobody twice.
            $schedule->command('usage:spending-threshold-alerts')->hourly()->withoutOverlapping();

            // Lane C §C7 — the same sweep for AGENCY SaaS client downgrades,
            // on the agencies' own connected accounts. Deliberately a separate
            // command from lane A's above: different money, different accounts,
            // and one lane's sweep must never touch the other's rows.
            $schedule->command('agency-subscriptions:apply-due-plan-changes')->hourly();

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

            // Unified Business Home and COO Decision Engine Contract
            // §10.1 step 7 (slice AI-1) — releases AI usage reservations
            // older than config('ai.reservation_expiry_minutes'), never
            // auto-commits one. Mirrors ExpireStaleUsageReservations
            // above exactly.
            $schedule->job(new ExpireStaleAiReservations())->everyFiveMinutes();

            // Unified Business Home and COO Decision Engine Contract §8.2
            // (slice AI-3) — the scheduled COO insight triggers. Both only
            // queue GenerateCooInsight, whose off-request gates decide whether
            // anything is paid for; with AI switched off both queue nothing.
            $schedule->command('coo:dispatch-insight-reviews')->dailyAt('04:10')->withoutOverlapping();
            $schedule->command('coo:dispatch-insight-reviews', ['--monthly'])->monthlyOn(1, '05:10')->withoutOverlapping();

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

            // Platform Automations: state-based triggers every 15 minutes, and the
            // announcement lifecycle (publish due / expire lapsed) every minute.
            $schedule->command('platform-automation:sweep')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('platform-announcements:sweep')->everyMinute()->withoutOverlapping();

            // SEO Keyword Rank Tracking V1 — paid provider pipeline. Registered
            // unconditionally; each job owns its own fail-closed master switch
            // (seo.rank_tracking.enabled, default OFF). The hourly tick only
            // RESERVES budgeted runs; the five-minute sweep moves them along and
            // can create no new spend; pruning is the 13-month retention.
            $schedule->job(new \App\Jobs\Seo\ScheduleSeoRankChecks())->hourly()->withoutOverlapping();
            $schedule->job(new \App\Jobs\Seo\ProcessSeoRankChecks())->everyFiveMinutes()->withoutOverlapping();
            $schedule->job(new \App\Jobs\Seo\PruneSeoRankObservations())->dailyAt('03:40');
            $schedule->command('seo:rank-sync-locations')->weeklyOn(1, '04:20')->withoutOverlapping();

            // SEO Content Engine V1 — owner-scheduled articles go live at their time. publishDue()
            // re-checks every article first (an article that no longer passes goes back to Draft), so
            // a one-minute cadence only means "at the time they chose", never "publish whatever is waiting".
            $schedule->command('articles:publish-due')->everyMinute()->withoutOverlapping();

            // Google Ads Module V1 contract §5 — the daily, staggered Ads read
            // sync sweep. It only queues per-account jobs (deduplicated, behind
            // the project circuit breaker); registered unconditionally like the
            // GBP sweep and at a fixed off-peak time so it never coincides with
            // the 00:00 daily jobs. withoutOverlapping() stops a slow sweep
            // stacking on the next tick.
            $schedule->job(new SweepGoogleAdsSyncs())->dailyAt('02:40')->withoutOverlapping();

            // Meta Ads Module V1 (contract 24 §6) — the same shape for Meta,
            // 30 minutes after Google's sweep so the two never overlap, and
            // with its own call budget, breaker and quota.
            $schedule->job(new SweepMetaAdsSyncs())->dailyAt('03:10')->withoutOverlapping();

            // External Website Audit Mode V1 — queue the weekly re-check of every Business that keeps its
            // existing website. It only queues jobs (one active crawl per Business, a per-run limit, the
            // cadence from config/external_site_audit.php); the crawl itself runs on the queue.
            $schedule->command('website:recrawl-external')->dailyAt('04:20')->withoutOverlapping();

            // PR #295 Correction Round 1, item 6 — the one canonical
            // mechanism that advances a submitted carrier registration.
            // Never on a page render; 15 minutes is far more responsive
            // than the multi-day real review cadence needs, without
            // hammering the provider. withoutOverlapping() so a slow
            // provider round trip on one run can never stack with the
            // next tick.
            $schedule->job(new RefreshPendingMessagingRegistrations())->everyFifteenMinutes()->withoutOverlapping();

            // Review correction — the same cadence, for the same reason:
            // a local number's own carrier-side campaign assignment task
            // (Telnyx's own assignment endpoint returns a background task,
            // never an immediate confirmation) needs a reachable
            // mechanism to ever resolve Requested to Confirmed or Failed,
            // or a number would stay Requested — and therefore never
            // Ready — forever.
            $schedule->job(new RefreshPendingCampaignAssignments())->everyFifteenMinutes()->withoutOverlapping();
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
