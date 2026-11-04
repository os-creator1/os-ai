<?php

namespace App\Library\Messaging;

/** The held funds for one outbound message: the retail base and the estimated carrier surcharge. */
final readonly class TransportReservation
{
    public function __construct(
        public int $baseReservationId,
        public ?int $surchargeReservationId,
        public string $meterKey,
    ) {
    }
}
