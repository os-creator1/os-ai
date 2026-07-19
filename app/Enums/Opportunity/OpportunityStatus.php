<?php

namespace App\Enums\Opportunity;

enum OpportunityStatus: string
{
    case Open = 'open';
    case AwaitingApproval = 'awaiting_approval';
    case InProgress = 'in_progress';
    case Snoozed = 'snoozed';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
}
