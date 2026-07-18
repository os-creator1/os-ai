<?php

namespace App\Jobs;

use App\Library\Traits\Trackable;
use App\Models\Campaigns;
use Throwable;

class ScheduleCampaign extends Base
{
    use Trackable;

    protected Campaigns $campaign;

    public int  $timeout       = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(Campaigns $campaign)
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
        if ($this->campaign->isPaused()) {
            return;
        }

        try {
            $this->campaign->run();
        } catch (Throwable $exception) {
            $errorMsg = "Error scheduling campaign: ".$exception->getMessage()."\n".$exception->getTraceAsString();
            $this->campaign->failed($errorMsg);

            throw $exception;
        }
    }
}
