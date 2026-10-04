<?php

namespace App\Enums\PlatformAutomation;

enum PlatformRunState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case AwaitingApproval = 'awaiting_approval';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped, self::Cancelled], true);
    }
}
