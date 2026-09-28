<?php

namespace App\Enums\Coo;

/**
 * Contract §9.1 — what a cached COO insight is about.
 *
 * AI-3 writes `PerformanceDiagnosis`. Contract 19 sub-slice 19.C activates
 * `MoveExplanation`: an AI explanation of the deterministic
 * NextBestMoveSelector pick (see NextBestMoveSubject for its subject_type/
 * subject_id). Never a competing recommendation (R-1).
 */
enum CooInsightKind: string
{
    case PerformanceDiagnosis = 'performance_diagnosis';
    case MoveExplanation = 'move_explanation';
}
