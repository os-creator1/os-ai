<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCampaign extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove All Campaigns for Testing in local server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $this->info('Clearing all campaigns');
        if (config('app.stage') == 'local') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('automations')->truncate();
            DB::table('campaigns')->truncate();
            DB::table('campaigns_lists')->truncate();
            DB::table('campaigns_senderids')->truncate();
            DB::table('csv_data')->truncate();
            DB::table('failed_jobs')->truncate();
            DB::table('import_job_histories')->truncate();
            DB::table('jobs')->truncate();
            DB::table('job_batches')->truncate();
            DB::table('reports')->truncate();
            DB::table('job_monitors')->truncate();
            DB::table('tracking_logs')->truncate();
            DB::table('file_campaign_data')->truncate();
            DB::table('chat_boxes')->truncate();
            DB::table('chat_box_messages')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        }
        $this->info('All campaigns cleared');

        return 0;
    }
}
