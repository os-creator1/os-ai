<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §5.2 — the outcome of one SEO readiness checklist item.
 * A plain fact, never a score or grade.
 */
enum SeoReadinessState: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case NotApplicable = 'not_applicable';
}
