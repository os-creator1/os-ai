<?php

    namespace App\Jobs;

    use App\Library\Contracts\CampaignInterface;
    use App\Library\Exception\RateLimitExceeded;
    use App\Library\Traits\Trackable;
    use Exception;
    use Illuminate\Bus\Batchable;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Throwable;
    use function App\Helpers\plogger;

    class LoadCampaign implements ShouldQueue
    {
        use Trackable, Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public int  $timeout       = 86400; // 24h
        public bool $failOnTimeout = true;
        public int  $tries         = 1;
        public int  $maxExceptions = 1;

        protected CampaignInterface $campaign;
        protected int               $page;
        protected array             $listOfIds;

        /**
         * Create a new job instance.
         */
        public function __construct(CampaignInterface $campaign, int $page, array $listOfIds)
        {
            $this->campaign  = $campaign;
            $this->page      = $page;
            $this->listOfIds = $listOfIds;
        }

        /**
         * Execute the job.
         * @throws RateLimitExceeded
         * @throws Throwable
         */
        public function handle(): void
        {
            // Skip execution if batch cancelled
            if ($this->batch()?->cancelled()) {
                return;
            }

            $plogger = plogger($this->campaign->uid);
            $total   = count($this->listOfIds);
            $count   = 0;

            $this->logInfo("Starting LoadCampaign for page {$this->page} with {$total} contact(s)", $plogger);

            // Update last activity before heavy operations (prevent dead-job misidentification)
            $this->updateLastActivity($plogger);

            $method = $this->campaign->upload_type === 'file'
                ? 'loadBulkDeliveryJobsByIds'
                : 'loadDeliveryJobsByIds';

            try {
                $this->campaign->{$method}(function (ShouldQueue $deliveryJob) use (&$count, $total, $plogger) {
                    $this->batch()->add($deliveryJob);
                    $count++;

                    $this->logInfo("Loaded job {$count}/{$total}", $plogger);

                    // Check rate limit after each queued job
                    if ($this->campaign->checkDelayFlag()) {
                        $this->logWarning("Rate limit hit! Stopping at {$count}/{$total}", $plogger);
                        throw new RateLimitExceeded('Stop loading jobs due to rate limit');
                    }
                }, $this->page, $this->listOfIds);

                $this->logInfo("Completed loading {$count} job(s)", $plogger);

            } catch (RateLimitExceeded $e) {
                // Soft stop (graceful exit)
                $this->campaign->setError($e->getMessage());
                $this->logWarning('Rate limit exceeded — halted loading process', $plogger);
            } catch (Throwable $e) {
                // Handle unexpected errors gracefully
                $this->campaign->setError($e->getMessage());
                $this->logError("Unexpected error during load: {$e->getMessage()}", $plogger);
                throw $e; // Let Laravel mark job as failed
            }
        }

        /**
         * Log message to both campaign logger and plogger.
         * @throws Exception
         */
        protected function logInfo(string $message, $plogger): void
        {
            $plogger->info($message);
            $this->campaign->logger()->info($message);
        }

        /**
         * @throws Exception
         */
        protected function logWarning(string $message, $plogger): void
        {
            $plogger->warning($message);
            $this->campaign->logger()->warning($message);
        }

        /**
         * @throws Exception
         */
        protected function logError(string $message, $plogger): void
        {
            $plogger->error($message);
            $this->campaign->logger()->error($message);
        }

        /**
         * Update the campaign debug record with last activity timestamp.
         * @throws Exception
         */
        protected function updateLastActivity($plogger): void
        {
            $plogger->debug('Attempting to acquire campaign debug() lock...');
            $this->campaign->debug(static function (array $info) use ($plogger) {
                $plogger->debug('Lock acquired, updating last activity timestamp...');
                $info['last_activity_at'] = now()->toDateTimeString();

                return $info;
            });
        }

    }
