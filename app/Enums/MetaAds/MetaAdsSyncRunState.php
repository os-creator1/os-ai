<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §6 — meta_ads_sync_runs.state; same vocabulary as Google.
 * `Partial` means a report hit its page/row cap or the usage threshold
 * (failure_code row_cap / usage_high): truncated data is never presented as
 * complete.
 */
enum MetaAdsSyncRunState: string
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
