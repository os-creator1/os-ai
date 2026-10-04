<?php

namespace App\Enums\PlatformAutomation;

enum PlatformStepState: string
{
    case Pending = 'pending';
    case Waiting = 'waiting';
    case AwaitingApproval = 'awaiting_approval';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Rejected = 'rejected';
}
