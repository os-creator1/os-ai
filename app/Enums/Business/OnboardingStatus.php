<?php

namespace App\Enums\Business;

enum OnboardingStatus: string
{
    case NotStarted = 'not_started';
    case Started = 'started';
    case AnalysisPending = 'analysis_pending';
    case ResultsReady = 'results_ready';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
    case Failed = 'failed';
}
