<?php

namespace App\Library\Usage;

final readonly class ReservationResult
{
    public function __construct(
        public bool $granted,
        public ?int $reservationId,
        public ?string $denialReason,
    ) {
    }
}
