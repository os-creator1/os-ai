<?php

namespace App\Enums\Opportunity;

enum OpportunityFreshness: string
{
    case Current = 'current';
    case Stale = 'stale';
}
