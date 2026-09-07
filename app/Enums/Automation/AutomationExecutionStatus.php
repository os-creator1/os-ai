<?php

namespace App\Enums\Automation;

/**
 * B4 Business Automations — the bounded lifecycle of one row in the
 * automation_executions ledger (contract §4). There is deliberately no
 * "retrying" state: under the conservative at-most-once policy (§5) a
 * claimed execution in ANY of these states forecloses a second automatic
 * attempt for the same idempotency_key.
 */
enum AutomationExecutionStatus: string
{
    /** Claimed (row exists, action_claimed_at set), action not yet run. */
    case Pending = 'pending';

    /** The action completed (provider accepted the send / field written). */
    case Succeeded = 'succeeded';

    /** The action ran and failed — never automatically retried. */
    case Failed = 'failed';

    /**
     * Authoritative state changed between claim and action (automation
     * disabled, Business/Workspace inactive, entitlement revoked, channel
     * or SendingServer disabled, Contact moved) — nothing was sent.
     */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'accent',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
