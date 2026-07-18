<?php

    namespace App\Console\Commands;

    use App\Models\Campaigns;
    use App\Models\Reports;
    use Carbon\Carbon;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;

    class SetDonePendingCampaign extends Command
    {
        protected $signature   = 'app:set-done-pending-campaign';
        protected $description = 'Set Done Pending Campaign';

        public function handle()
        {
            Campaigns::where('status', Campaigns::STATUS_SENDING)
                ->where('run_at', '>=', Carbon::now()->subHours(6))
                ->where('upload_type', 'normal')
                ->where('schedule_type', '!=', Campaigns::TYPE_RECURRING)
                ->orderBy('id')
                ->chunkById(50, function ($campaigns) {
                    // Collect campaign IDs for bulk report counting
                    $campaignIds = $campaigns->pluck('id')->toArray();

                    $reportCounts = Reports::select('campaign_id', DB::raw('count(*) as total'))
                        ->whereIn('campaign_id', $campaignIds)
                        ->groupBy('campaign_id')
                        ->pluck('total', 'campaign_id');

                    foreach ($campaigns as $campaign) {
                        $countData = $reportCounts[$campaign->id] ?? 0;

                        $cache                 = json_decode($campaign->cache, true) ?? [];
                        $cache['ContactCount'] = $countData;
                        $campaign->cache       = json_encode($cache);

                        $campaign->setDone(); // assuming this sets status internally
                        $campaign->delivery_at = now();
                        $campaign->save();
                    }
                });

            $this->info('Done pending campaigns updated successfully.');
        }

    }
