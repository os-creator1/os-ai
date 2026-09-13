<?php

namespace App\Enums\Coo;

/**
 * Contract §9.3 — why a cached insight stopped being shown. Invalidation is
 * soft (the row stays for audit) and never, by itself, queues or spends.
 */
enum CooInsightInvalidationReason: string
{
    /** The bucketed facts read next no longer match the insight's fingerprint. */
    case SignalChanged = 'signal_changed';

    /** Work the insight cited was completed, dismissed, succeeded or failed. */
    case SubjectWorkChanged = 'subject_work_changed';

    /** The Business's context moved: website published, Google connection, profile. */
    case ContextChanged = 'context_changed';
}
