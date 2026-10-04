<?php

namespace App\Enums\Seo;

/** Absence is explicit: a position exists only when Found. */
enum SeoRankObservationStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case NotMatched = 'not_matched';
}
