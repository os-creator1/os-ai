<?php

namespace App\Console\Commands;

use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager;
use Illuminate\Console\Command;

class PlatformAnnouncementsSweepCommand extends Command
{
    protected $signature = 'platform-announcements:sweep';

    protected $description = 'Publish due scheduled announcements and expire lapsed ones';

    public function handle(PlatformAnnouncementManager $announcements): int
    {
        $result = $announcements->sweep();

        if ($result['published'] > 0 || $result['expired'] > 0) {
            $this->line("published {$result['published']}, expired {$result['expired']}");
        }

        return self::SUCCESS;
    }
}
