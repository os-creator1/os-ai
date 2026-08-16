<?php

namespace App\Library\Usage;

final readonly class CommitResult
{
    public function __construct(
        public int $reservationId,
        public string $finalAmountMicro,
        public string $reservedAmountMicro,
        public bool $hadOverage,
        public bool $hadUnusedRelease,
    ) {
    }
}
