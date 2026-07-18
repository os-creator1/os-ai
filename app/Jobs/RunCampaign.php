<?php

    namespace App\Jobs;

    use App\Library\Contracts\CampaignInterface;
    use App\Library\Traits\Trackable;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Throwable;

    class RunCampaign implements ShouldQueue
    {
        use Trackable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        protected CampaignInterface $campaign;

        public int  $timeout       = 300;
        public bool $failOnTimeout = true;
        public int  $tries         = 1;
        public int  $maxExceptions = 1;

        /**
         * Create a new job instance.
         */
        public function __construct(CampaignInterface $campaign)
        {
            $this->campaign = $campaign;
        }

        /**
         * Execute the job.
         *
         * @throws Throwable
         */
        public function handle(): void
        {
            // Skip execution if campaign is paused
            if ($this->campaign->isPaused()) {
                $this->campaign->logger()->info('Campaign execution skipped (paused)');

                return;
            }

            $sessionId = now()->format('Y-m-d_H:i:s');
            $startAt   = now();

            try {
                // Reset debug info
                $this->campaign->cleanupDebug();

                // Store start time for debugging context
                $this->campaign->debug(static function (array $info) use ($startAt) {
                    $info['start_at'] = $startAt->toDateTimeString();

                    return $info;
                });

                // Log campaign start
                $this->campaign->logger()->info("Launching campaign job: session={$sessionId}");

                // Run the campaign
                $this->campaign->logger()->debug('Preparing to send campaign messages...');
                $this->campaign->run();

                // Log completion with duration
                $duration = $startAt->diffInSeconds(now());
                $this->campaign->logger()->info("Campaign job completed successfully in {$duration}s.");

            } catch (Throwable $e) {
                // Log and limit error message size
                $errorMsg = sprintf(
                    "Error scheduling campaign: %s\n%s",
                    $e->getMessage(),
                    $e->getTraceAsString()
                );

                $this->campaign->setError(substr($errorMsg, 0, 1000));

                $this->campaign->logger()->error("Campaign execution failed: {$e->getMessage()}");

                throw $e; // Ensure Laravel marks the job as failed
            }
        }

    }
