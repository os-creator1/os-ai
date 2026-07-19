<?php

namespace App\Enums\Opportunity;

enum OpportunityActionExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
