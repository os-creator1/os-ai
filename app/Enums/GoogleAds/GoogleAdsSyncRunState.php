<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §4 — google_ads_sync_runs.state. `Partial` means a report hit its
 * page/row cap (failure_code = row_cap): truncated data is never presented
 * as complete.
 */
enum GoogleAdsSyncRunState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }
}
