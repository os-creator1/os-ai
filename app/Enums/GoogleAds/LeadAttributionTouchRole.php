<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §10 — first-touch / last-touch role of an append-only touch row.
 */
enum LeadAttributionTouchRole: string
{
    case First = 'first';
    case Last = 'last';
}
