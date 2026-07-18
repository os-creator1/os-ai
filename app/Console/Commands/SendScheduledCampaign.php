<?php

namespace App\Console\Commands;

use App\Models\Campaigns;
use Exception;
use Illuminate\Console\Command;

class SendScheduledCampaign extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Recurring Campaigns';

    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle()
    {
        Campaigns::checkAndExecuteScheduledCampaigns();
    }
}
