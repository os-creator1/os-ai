<?php

namespace App\Enums\Opportunity;

enum OpportunityRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
