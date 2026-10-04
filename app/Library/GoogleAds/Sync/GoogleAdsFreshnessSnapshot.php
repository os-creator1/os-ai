<?php

namespace App\Library\GoogleAds\Sync;

use Carbon\CarbonInterface;

final readonly class GoogleAdsFreshnessSnapshot
{
    public function __construct(
        public GoogleAdsFreshnessState $state,
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
        return $this->state === GoogleAdsFreshnessState::FailedWithData
            || ($this->state !== GoogleAdsFreshnessState::NeverSynced && $this->isStale);
    }
}
