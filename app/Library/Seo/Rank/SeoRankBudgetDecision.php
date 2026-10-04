<?php

namespace App\Library\Seo\Rank;

use App\Models\SeoRankCheckRun;

/**
 * Outcome of asking SeoRankTrackingBudget for permission to create a paid run.
 * `allowed` is true ONLY when a NEW run (with its ledger reservation) was just
 * created; every other outcome carries a closed `reason` and, for idempotent
 * hits and pending checks, the run that already exists.
 */
final class SeoRankBudgetDecision
{
    public const DISABLED = 'disabled';
    public const NOT_ENTITLED = 'not_entitled';
    public const TARGET_INACTIVE = 'target_inactive';
    public const OVER_SLOT_LIMIT = 'over_slot_limit';
    public const NOT_DUE = 'not_due';
    public const ALREADY_SCHEDULED = 'already_scheduled';
    public const PENDING = 'pending';
    public const RECENT_RESULT = 'recent_result';
    public const COOLDOWN = 'cooldown';
    public const BUSINESS_CAP = 'business_cap';
    public const WORKSPACE_CAP = 'workspace_cap';
    public const GLOBAL_DAILY_CAP = 'global_daily_cap';
    public const GLOBAL_MONTHLY_CAP = 'global_monthly_cap';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?SeoRankCheckRun $run,
    ) {
    }

    public static function created(SeoRankCheckRun $run): self
    {
        return new self(true, null, $run);
    }

    public static function refused(string $reason, ?SeoRankCheckRun $existing = null): self
    {
        return new self(false, $reason, $existing);
    }

    /** True when the refusal is a spend-limit pause the owner should be told about. */
    public function isBudgetPause(): bool
    {
        return in_array($this->reason, [
            self::BUSINESS_CAP,
            self::WORKSPACE_CAP,
            self::GLOBAL_DAILY_CAP,
            self::GLOBAL_MONTHLY_CAP,
        ], true);
    }

    /** The provider is switched off or not configured: not a spend pause. */
    public function isUnavailable(): bool
    {
        return $this->reason === self::DISABLED;
    }
}
