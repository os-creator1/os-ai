<?php

    namespace App\Jobs;

    use App\Library\Traits\Trackable;
    use Monolog\Formatter\LineFormatter;
    use Monolog\Handler\StreamHandler;
    use Monolog\Logger;

    class ImportCustomers extends Base
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

        /**
         * Execute the job.
         *
         * @return void
         */
        public function handle()
        {
            // Use a logger to log failed
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

            // File path:
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

                    // Write log, to make sure the file is created
                    $logger->info($message);
                    $logger->info(sprintf('Processed: %s/%s, Skipped: %s', $processed, $total, $failed));
                },
                function ($invalidRecord, $error) use ($logger) {
                    $errorMessages = is_array($error) ? $error : [$error];

                    $logger->warning(
                        'Invalid record: [' . implode(",", array_values($invalidRecord)) . '] | Validation error: ' . implode(";", $errorMessages)
                    );
                }

            );

            $logger->info('Finished');
        }

    }
