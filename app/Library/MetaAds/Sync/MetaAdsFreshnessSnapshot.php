<?php

namespace App\Library\MetaAds\Sync;

use Carbon\CarbonInterface;

final readonly class MetaAdsFreshnessSnapshot
{
    public function __construct(
        public MetaAdsFreshnessState $state,
        public ?CarbonInterface $lastSuccessfulSyncAt,
        public ?CarbonInterface $dataThroughDate,
        public ?string $lastFailureCode,
        public ?string $lastFailureLabel,
        public bool $isStale,
    ) {
    }

    /** True when the page should show the "refresh problem" warning above its data. */
    public function shouldWarn(): bool
    {
        return $this->state === MetaAdsFreshnessState::FailedWithData
            || ($this->state !== MetaAdsFreshnessState::NeverSynced && $this->isStale);
    }
}
