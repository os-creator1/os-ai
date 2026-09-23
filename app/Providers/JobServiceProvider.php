<?php

    namespace App\Providers;

    use Illuminate\Queue\Events\JobProcessed;
    use Illuminate\Queue\Events\JobFailed;
    use Illuminate\Queue\Events\JobProcessing;
    use Illuminate\Contracts\Encryption\Encrypter;
    use Illuminate\Support\ServiceProvider;
    use Illuminate\Support\Facades\Queue;
    use App\Library\Log as MailLog;
    use Throwable;

    class JobServiceProvider extends ServiceProvider
    {
        /**
         * Bootstrap the application services.
         */
        public function boot()
        {
            // IMPORTANT:
            // ONLY TRIGGER QUEUE EVENTS FOR JOB_MONITORS THAT DO NOT HAVE BATCH
            // IT IS BECAUSE ONES WIH A BATCH WILL BE UPDATED BY BATCH EVENTS, EXCEPT FOR "BEFORE"
            // Initialize the MailLog which writes logs to mail.log
            $this->initMailLog();

            // 'before' event is triggered for standalone jobs only, NOT batch jobs
            Queue::before(function (JobProcessing $event) {
                // @performance issue here, do not un-serialize all the time
                $job = $this->getJobObject($event);
                if ($job !== null && property_exists($job, 'monitor')) {
                    $monitor = $job->monitor;
                    if (is_null($monitor->batch_id)) {
                        // With batch, status is already set in the campaign.run() method (executed by RunCampaign job)
                        $monitor->setRunning();
                    }
                }
            });

            // 'after' event is triggered for standalone jobs only, NOT batch jobs
            Queue::after(function (JobProcessed $event) {
                $job = $this->getJobObject($event);
                if ($job !== null && property_exists($job, 'monitor')) {
                    $monitor = $job->monitor;
                    if (is_null($monitor->batch_id)) {
                        $monitor->setDone();
                    }
                }
            });

            // 'failing' event is triggered for standalone jobs only, NOT batch jobs
            Queue::failing(function (JobFailed $event) {
                $job = $this->getJobObject($event);
                if ($job !== null && property_exists($job, 'monitor')) {
                    $monitor = $job->monitor;
                    if (is_null($monitor->batch_id)) {
                        $monitor->setFailed($event->exception);
                    }
                }
            });
        }

        /**
         * Register the application services.
         */
        public function register()
        {
            //
        }

        /**
         * The queued job object behind a queue event, for the monitor above.
         *
         * A job that implements Illuminate\Contracts\Queue\ShouldBeEncrypted
         * (Contract 17 §11.3 — the document-link delivery job, which carries a
         * secure token that must never sit in `jobs`/`failed_jobs` in the
         * clear) has an ENCRYPTED `command` payload, and unserialize() alone
         * fatals on it. The two-line rule below is Laravel's own, copied from
         * Illuminate\Queue\CallQueuedHandler::getCommand(): a plain serialized
         * object always starts with "O:", anything else is ciphertext and is
         * decrypted first. Behaviour for every existing unencrypted job is
         * byte-for-byte unchanged.
         *
         * Returns null rather than throwing when a payload cannot be read at
         * all, so a single unreadable job can never take down the worker's
         * event listeners; callers null-check before touching $job->monitor.
         */
        private function getJobObject($event)
        {
            $command = $event->job->payload()['data']['command'] ?? null;

            if (! is_string($command)) {
                return null;
            }

            try {
                if (! str_starts_with($command, 'O:') && app()->bound(Encrypter::class)) {
                    $command = app(Encrypter::class)->decrypt($command);
                }

                $job = unserialize($command);
            } catch (Throwable) {
                // Never re-thrown and never logged: a payload may contain a
                // secret, so neither it nor the decryption failure detail is
                // allowed to reach a log line or an exception message.
                return null;
            }

            return is_object($job) ? $job : null;
        }

        /**
         * Init the MailLog.
         */
        private function initMailLog()
        {
            MailLog::configure(storage_path() . '/logs/' . php_sapi_name() . '/mail.log');
        }

    }
