<?php

namespace App\Console\Commands;

use App\Jobs\Seo\MaintainAutopilotArticlesJob;
use App\Models\ContentAutopilotSetting;
use Illuminate\Console\Command;

/**
 * Content Autopilot - the weekly maintenance sweep. Like the daily tick it only queues one job per switched-on Business, spread
 * over several hours. Free: no AI, no provider call (a rewrite, when one is warranted, is a separate queued writer job).
 */
class MaintainContentAutopilot extends Command
{
    protected $signature = 'content:autopilot-maintain {--spread-hours=8 : Spread the queued reviews over this many hours}';

    protected $description = 'Queue a weekly review of existing articles for every Business that has Content Autopilot switched on';

    public function handle(): int
    {
        $spread = max(1, (int) $this->option('spread-hours')) * 3600;
        $queued = 0;

        ContentAutopilotSetting::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('paused_reason')->orWhere('paused_reason', '!=', 'owner'))
            ->orderBy('id')
            ->chunkById(100, function ($settings) use ($spread, &$queued) {
                foreach ($settings as $setting) {
                    MaintainAutopilotArticlesJob::dispatch((int) $setting->business_id)->delay(now()->addSeconds(($setting->business_id * 6007) % $spread));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} Content Autopilot maintenance review(s).");

        return self::SUCCESS;
    }
}
