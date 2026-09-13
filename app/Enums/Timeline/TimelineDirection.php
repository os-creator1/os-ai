<?php

namespace App\Enums\Timeline;

/**
 * Which side of the relationship a message came from: the person (inbound) or
 * the Business (outbound). Only a Message has a direction.
 */
enum TimelineDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
