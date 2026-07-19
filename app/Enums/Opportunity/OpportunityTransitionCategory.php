<?php

namespace App\Enums\Opportunity;

enum OpportunityTransitionCategory: string
{
    case Workflow = 'workflow';
    case Freshness = 'freshness';
}
