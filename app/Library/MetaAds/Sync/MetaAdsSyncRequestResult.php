<?php

namespace App\Library\MetaAds\Sync;

use App\Models\MetaAdsSyncRun;
use Carbon\CarbonInterface;

/**
 * Outcome of asking for a sync. `nextAllowedAt` is set for Throttled and
 * FreshEnough (when a manual refresh will next be accepted); `run` only for
 * Queued.
 */
final readonly class MetaAdsSyncRequestResult
{
    private function __construct(
        public MetaAdsSyncRequestOutcome $outcome,
        public ?CarbonInterface $nextAllowedAt = null,
        public ?MetaAdsSyncRun $run = null,
    ) {
    }

    public static function queued(MetaAdsSyncRun $run): self
    {
        return new self(MetaAdsSyncRequestOutcome::Queued, null, $run);
    }

    public static function throttled(CarbonInterface $nextAllowedAt): self
    {
        return new self(MetaAdsSyncRequestOutcome::Throttled, $nextAllowedAt);
    }

    public static function freshEnough(CarbonInterface $nextAllowedAt): self
    {
        return new self(MetaAdsSyncRequestOutcome::FreshEnough, $nextAllowedAt);
    }

    public static function alreadyRunning(): self
    {
        return new self(MetaAdsSyncRequestOutcome::AlreadyRunning);
    }

    public static function notSyncable(): self
    {
        return new self(MetaAdsSyncRequestOutcome::NotSyncable);
    }

    public function wasQueued(): bool
    {
        return $this->outcome === MetaAdsSyncRequestOutcome::Queued;
    }
}
