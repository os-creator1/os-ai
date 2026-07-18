<?php

    namespace App\Console\Commands;

    use App\Models\JobMonitor;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;
    use Throwable;

    class CleanUpJobMonitors extends Command
    {
        protected $signature   = 'jobs:cleanup-monitors';
        protected $description = 'Clean up completed job monitors and related batches every 60 minutes';

        /**
         * @throws Throwable
         */
        public function handle()
        {
            DB::transaction(function () {
                // 1. Collect distinct batch_ids where status is "done"
                $batchIds = JobMonitor::where('status', 'done')
                    ->whereNotNull('batch_id')
                    ->distinct()
                    ->pluck('batch_id');

                // 2. Delete "done" job_monitors (with batch_id in list) and
                //    also delete "done" monitors without batch_id
                $deletedJobMonitors = JobMonitor::where('status', 'done')
                    ->where(function ($query) use ($batchIds) {
                        $query->whereIn('batch_id', $batchIds)
                            ->orWhereNull('batch_id');
                    })
                    ->delete();

                // 3. Delete related job_batches (only if we have batchIds)
                $deletedBatches = 0;
                if ($batchIds->isNotEmpty()) {
                    $deletedBatches = DB::table('job_batches')
                        ->whereIn('id', $batchIds)
                        ->delete();
                }

                // 4. Truncate related tables if they have rows
                $tablesToTruncate = ['failed_jobs', 'jobs', 'import_job_histories'];
                foreach ($tablesToTruncate as $table) {
                    if (DB::table($table)->exists()) {
                        DB::table($table)->truncate();
                    }
                }

                $this->info("✅ Clean up complete:");
                $this->info(" - Job monitors deleted: {$deletedJobMonitors}");
                $this->info(" - Job batches deleted: {$deletedBatches}");
                $this->info(" - Truncated tables: " . implode(', ', $tablesToTruncate));
            });
        }

    }
