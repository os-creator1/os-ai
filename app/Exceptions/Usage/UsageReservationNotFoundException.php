<?php

namespace App\Exceptions\Usage;

use RuntimeException;

class UsageReservationNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct("No business_usage_reservations row exists with id #{$reservationId}.");
    }
}
