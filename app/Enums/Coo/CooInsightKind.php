<?php

namespace App\Enums\Coo;

/**
 * Contract §9.1 — what a cached COO insight is about.
 *
 * AI-3 writes only `PerformanceDiagnosis`. `MoveExplanation` is reserved by
 * the contract and deliberately unused until a slice defines its subject.
 */
enum CooInsightKind: string
{
    case PerformanceDiagnosis = 'performance_diagnosis';
    case MoveExplanation = 'move_explanation';
}
