<?php

    namespace App\Jobs;

    use App\Library\Traits\Trackable;
    use App\Models\FailedContactImport;
    use Monolog\Formatter\LineFormatter;
    use Monolog\Handler\StreamHandler;
    use Monolog\Logger;

    /**
     * @method batch()
     */
    class ImportContacts extends Base
    {

        use Trackable;

        /* Already specified by Base job
         *
         *     public $failOnTimeout = true;
         *     public $tries = 1;
         *
         */

        public int $timeout = 7200;

        // @todo this should better be a constant
        protected $list;
        protected $file;
        protected $map;

        /**
         * Create a new job instance.
         *
         * @return void
         */
        public function __construct($list, $file, $map)
        {
            $this->list = $list;
            $this->file = $file;
            $this->map  = $map;

            // Set the initial value for progress check
            $this->afterDispatched(function ($thisJob, $monitor) {
                $monitor->setJsonData([
                    'percentage' => 0,
                    'total'      => 0,
                    'processed'  => 0,
                    'failed'     => 0,
                    'message'    => __('locale.contacts.import_being_queued_for_processing'),
                    'logfile'    => null,
                ]);
            });
        }

        public function handle(): void
        {
            $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message%\n");
            $logfile   = $this->file . ".log";
            $stream    = new StreamHandler($logfile, Logger::DEBUG);
            $stream->setFormatter($formatter);

            $pid    = getmypid();
            $logger = new Logger($pid);
            $logger->pushHandler($stream);

            $this->monitor->updateJsonData([
                'logfile' => $logfile,
            ]);

            // Write log, to make sure the file is created
            $logger->info('Initiated');

            $this->list->import(
                $this->file,
                $this->map,
                function ($processed, $total, $failed, $message) use ($logger) {
                    $percentage = ($total && $processed) ? (int) ($processed * 100 / $total) : 0;
                    $this->monitor->updateJsonData([
                        'percentage' => $percentage,
                        'total'      => $total,
                        'processed'  => $processed,
                        'failed'     => $failed,
                        'message'    => $message,
                    ]);
                    $logger->info($message);
                    $logger->info(sprintf('Processed: %s/%s, Skipped: %s', $processed, $total, $failed));
                },
                function ($invalidRecord, $reason) {
                    // Store the failed record in the database
                    FailedContactImport::create([
                        'user_id'          => $this->list->customer_id,
                        'contact_group_id' => $this->list->id,
                        'record_data'      => $invalidRecord,
                        'reason'           => $reason,
                        'job_id'           => $this->job->getJobId(),
                    ]);
                }
            );

            $logger->info('Finished');
        }

    }
