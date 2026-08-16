<?php

namespace App\Enums\Usage;

enum UsageReservationStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';
}
