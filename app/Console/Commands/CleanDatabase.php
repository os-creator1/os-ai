<?php

namespace App\Console\Commands;

use App\Models\JobMonitor;
use DB;
use Illuminate\Console\Command;

class CleanDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean unwanted Database memory';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::table('failed_jobs')->whereDate('failed_at', '<=', now()->subDays(30))->delete();
        DB::table('import_job_histories')->whereDate('created_at', '<=', now()->subDays(30))->delete();
        DB::table('jobs')->whereDate('created_at', '<=', now()->subDays(30))->delete();
        DB::table('job_batches')->whereDate('created_at', '<=', now()->subDays(30))->delete();
        JobMonitor::whereDate('created_at', '<=', now()->subDays(30))->where('status', 'done')->delete();
    }
}
