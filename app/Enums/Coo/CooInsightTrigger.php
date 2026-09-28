<?php

namespace App\Enums\Coo;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;

/**
 * Contract §8.2 — the four ways a PerformanceDiagnosis insight is ever paid
 * for, plus Contract 19 §12 19.C's own trigger for a MoveExplanation insight.
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

    /**
     * Contract 19 §12 19.C — whether the deterministic NextBestMoveSelector's
     * current pick can be explained. Fired from the same background points as
     * E-1/E-2 (the daily sweep and "work finished"); its own condition is
     * simply "a move is currently selected", never E-1's material-signal gate.
     */
    case MoveExplanation = 'c19_move_explanation';

    /** E-4 is the customer asking; every other trigger is the product noticing. */
    public function lane(): AiLane
    {
        return $this === self::ExplainThisChange ? AiLane::Interactive : AiLane::Product;
    }

    public function category(): AiUsageCategory
    {
        return match ($this) {
            self::ExplainThisChange => AiUsageCategory::CooInteractive,
            self::MoveExplanation => AiUsageCategory::CooMoveExplanation,
            self::MultiSignalChange, self::WorkFinished, self::MonthlyReview => AiUsageCategory::CooDiagnosis,
        };
    }

    /** Every scheduled/background trigger is dormancy-gated (§8.1); a customer's own ask is not. */
    public function isDormancyGated(): bool
    {
        return $this !== self::ExplainThisChange;
    }

    /** Contract 19 §5.1 — which coo_insights.kind this trigger writes. */
    public function kind(): CooInsightKind
    {
        return $this === self::MoveExplanation ? CooInsightKind::MoveExplanation : CooInsightKind::PerformanceDiagnosis;
    }

    /**
     * Implementation Contract 19 §5.9 — who caused the row, and therefore who
     * may read it back.
     *
     * E-4 is a human asking, so the row is `on_demand` and is attributed to,
     * and readable only by, that actor. E-1…E-3 are schedules: nobody asked,
     * so the row is `system`, carries no actor, and is computed for the
     * declared audience of §5.9b.
     */
    public function origin(): CooInsightOrigin
    {
        return $this === self::ExplainThisChange ? CooInsightOrigin::OnDemand : CooInsightOrigin::System;
    }
}
