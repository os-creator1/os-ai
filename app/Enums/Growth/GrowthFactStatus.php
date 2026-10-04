<?php

namespace App\Enums\Growth;

/**
 * The state of one domain's fact reader for one Business. Only `Available`
 * lets a rule evaluate. Every other state EXCLUDES the rule (and its score
 * category) — it is never translated to "zero" or "all clear":
 *
 *   Unavailable  the module does not exist / is not live on this platform
 *   NotConnected the module exists but this Business has not connected it
 *   NotEntitled  the module exists but this Business's plan does not include it
 *   Available    facts were read and rules may judge them
 */
enum GrowthFactStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case NotConnected = 'not_connected';
    case NotEntitled = 'not_entitled';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
