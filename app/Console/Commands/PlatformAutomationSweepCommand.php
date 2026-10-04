<?php

namespace App\Console\Commands;

use App\Library\PlatformAutomation\PlatformScheduledTriggers;
use Illuminate\Console\Command;

/**
 * Scheduler entry points for Platform Automations:
 *   platform-automation:sweep      state-based triggers (trial ending, onboarding, ...) — every 15 min
 *   platform-announcements:sweep   publish due / expire lapsed announcements — every minute
 */
class PlatformAutomationSweepCommand extends Command
{
    protected $signature = 'platform-automation:sweep';

    protected $description = 'Find state-based Platform automation triggers (trial ending, incomplete onboarding, ...) and start their runs';

    public function handle(PlatformScheduledTriggers $triggers): int
    {
        foreach ($triggers->sweep() as $trigger => $created) {
            if ($created > 0) {
                $this->line("{$trigger}: {$created} run(s) started");
            }
        }

        return self::SUCCESS;
    }
}
