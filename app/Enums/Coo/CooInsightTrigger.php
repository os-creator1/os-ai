<?php

namespace App\Enums\Coo;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;

/**
 * Contract §8.2 — the only four ways a COO insight is ever paid for.
 */
enum CooInsightTrigger: string
{
    /** E-1 — at least two canonical metrics changed materially, and no rule explains them. */
    case MultiSignalChange = 'e1_multi_signal_change';

    /** E-2 — work the COO surfaced finished, and E-1 still holds afterwards. */
    case WorkFinished = 'e2_work_finished';

    /** E-3 — the monthly review, only when the fingerprint moved since the last insight. */
    case MonthlyReview = 'e3_monthly_review';

    /** E-4 — the customer's own "Explain this change", once per subject per window. */
    case ExplainThisChange = 'e4_explain_this_change';

    /** E-4 is the customer asking; E-1…E-3 are the product noticing. */
    public function lane(): AiLane
    {
        return $this === self::ExplainThisChange ? AiLane::Interactive : AiLane::Product;
    }

    public function category(): AiUsageCategory
    {
        return $this === self::ExplainThisChange ? AiUsageCategory::CooInteractive : AiUsageCategory::CooDiagnosis;
    }

    /** Scheduled product work is never spent on a dormant Business (§8.1); a customer's own ask is. */
    public function isDormancyGated(): bool
    {
        return $this !== self::ExplainThisChange;
    }
}
