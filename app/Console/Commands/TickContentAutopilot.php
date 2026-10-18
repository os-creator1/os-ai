<?php

namespace App\Console\Commands;

use App\Jobs\Seo\RunContentAutopilotJob;
use App\Models\ContentAutopilotSetting;
use Illuminate\Console\Command;

/**
 * Content Autopilot - the daily sweep. It does nothing but queue one RunContentAutopilotJob per switched-on Business, spread
 * across the next several hours by a stable per-Business offset (so the work, and any writer calls it leads to, never bunch up).
 * Free: no AI, no provider call. A Business the owner paused is skipped here; the runner re-checks every gate itself.
 */
class TickContentAutopilot extends Command
{
    protected $signature = 'content:autopilot-tick {--spread-hours=6 : Spread the queued runs over this many hours}';

    protected $description = 'Queue a Content Autopilot run for every Business that has it switched on';

    public function handle(): int
    {
        $spread = max(1, (int) $this->option('spread-hours')) * 3600;
        $queued = 0;

        ContentAutopilotSetting::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('paused_reason')->orWhere('paused_reason', '!=', 'owner'))
            ->orderBy('id')
            ->chunkById(100, function ($settings) use ($spread, &$queued) {
                foreach ($settings as $setting) {
                    RunContentAutopilotJob::dispatch((int) $setting->business_id)->delay(now()->addSeconds(($setting->business_id * 7919) % $spread));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} Content Autopilot run(s).");

        return self::SUCCESS;
    }
}
