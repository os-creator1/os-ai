<?php

namespace App\Library\GoogleAds\Sync;

use App\Models\GoogleAdsSyncRun;
use Carbon\CarbonInterface;

/**
 * Outcome of asking for a sync. `nextAllowedAt` is set for Throttled and
 * FreshEnough (when a manual refresh will next be accepted); `run` only for
 * Queued.
 */
final readonly class GoogleAdsSyncRequestResult
{
    private function __construct(
        public GoogleAdsSyncRequestOutcome $outcome,
        public ?CarbonInterface $nextAllowedAt = null,
        public ?GoogleAdsSyncRun $run = null,
    ) {
    }

    public static function queued(GoogleAdsSyncRun $run): self
    {
        return new self(GoogleAdsSyncRequestOutcome::Queued, null, $run);
    }

    public static function throttled(CarbonInterface $nextAllowedAt): self
    {
        return new self(GoogleAdsSyncRequestOutcome::Throttled, $nextAllowedAt);
    }

    public static function freshEnough(CarbonInterface $nextAllowedAt): self
    {
        return new self(GoogleAdsSyncRequestOutcome::FreshEnough, $nextAllowedAt);
    }

    public static function alreadyRunning(): self
    {
        return new self(GoogleAdsSyncRequestOutcome::AlreadyRunning);
    }

    public static function notSyncable(): self
    {
        return new self(GoogleAdsSyncRequestOutcome::NotSyncable);
    }

    public function wasQueued(): bool
    {
        return $this->outcome === GoogleAdsSyncRequestOutcome::Queued;
    }
}
