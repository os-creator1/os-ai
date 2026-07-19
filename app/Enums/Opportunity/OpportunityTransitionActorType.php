<?php

namespace App\Enums\Opportunity;

enum OpportunityTransitionActorType: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case Worker = 'worker';
    case System = 'system';
}
