<?php

namespace App\Jobs;

use App\Models\Campaigns;
use Throwable;

class StoreCampaignJob extends Base
{
    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public bool $deleteWhenMissingModels = true;

    protected $campaign_id;

    /**
     * Create a new job instance.
     *
     * @param $campaign_id
     */
    public function __construct($campaign_id)
    {
        $this->campaign_id = $campaign_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Throwable
     */
    public function handle()
    {
        $campaign = Campaigns::find($this->campaign_id);
        if ($campaign) {
            $campaign->execute();
        }
    }

    public function failed(Throwable $exception)
    {
        $campaign = Campaigns::find($this->campaign_id);
        $campaign->failed($exception->getMessage());
    }
}
